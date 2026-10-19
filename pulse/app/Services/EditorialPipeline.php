<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Setting;
use App\Support\EditorialPolicy;
use App\Support\PublishGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * THE NEWSROOM PIPELINE — every AI-written or AI-rewritten article goes through
 * here, so the same editorial policy and checks apply to scheduled jobs, the
 * breaking fast lane, queue workers and manual commands alike.
 *
 *   2. research   — the story's own source + independent coverage (WebResearcher),
 *                   ranked wire/official first, with publisher/date/access records
 *   3. validation — too little evidence ⇒ the story is not written at all
 *   4. writing    — Rewriter::writeArticle() under EditorialPolicy
 *   5. fact-check — FactChecker (separate call, sources only)
 *   7. revision   — at most ONE targeted fix pass, then a re-check
 *   6. quality    — PublishGate originality rules (repetition, stock phrases, …)
 *   9. records    — policy_version, fact_check, review_status, ai_usage, sources
 *
 * New stories publish automatically only with review_status "ready" (PublishGate
 * enforces it). Revisions of published articles are saved as PostRevision drafts
 * and never touch the live article until an editor applies them.
 */
class EditorialPipeline
{
    public function __construct(
        private Rewriter $rewriter,
        private FactChecker $checker,
        private WebResearcher $researcher,
        private ArticleFetcher $fetcher,
        private SeoOptimizer $seo,
    ) {}

    /**
     * Stages 2-9 for a NEW story (a queued draft). Returns null when the draft
     * was written and recorded (check $post->review_status), or a reason it was
     * left unwritten (e.g. not enough independent sources yet).
     */
    public function processDraft(Post $post, int $minSources): ?string
    {
        $sources = $this->gatherSources($post, max(0, (int) Setting::get('max_sources_per_post', 4)));
        if (count($sources) < $minSources) {
            return 'only ' . count($sources) . " usable source(s) (need {$minSources})";
        }

        // Not enough credible evidence: say so now, before any paid writing call.
        $assessment = SourcePacket::assess($sources);
        if (! $assessment['sufficient']) {
            return 'needs reporting: ' . implode(' ', $assessment['reasons']);
        }

        $ai = new AiClient;
        $brief = ['title' => $post->title, 'category' => $post->category?->name ?? 'News', 'sources' => $sources, 'allow_needs_reporting' => true];
        $draft = $this->rewriter->writeArticle($ai, 'new', $brief);
        if (! $draft) {
            return 'the writer produced nothing usable';
        }
        if (! empty($draft['needs_reporting'])) {
            return 'needs reporting: ' . implode(' ', $draft['reporting_gaps']);
        }

        [$draft, $fc] = $this->checkAndFix($ai, $draft, $sources, null);

        $post->forceFill([
            'title' => $draft['headline'] ?: $post->title,      // slug was set at ingest and does not change
            'excerpt' => $draft['excerpt'] ?: $post->excerpt,
            'body' => $draft['body'],
            'takeaways' => \App\Support\ArticleSections::takeaways($draft['takeaways'] ?? []),
            'meta_title' => $draft['meta_title'] ?: $post->meta_title,
            'meta_description' => $draft['meta_description'] ?: $post->meta_description,
            'sources' => $this->sourceRecords($sources, $fc),
            'story_type' => $draft['story_type'],
            'policy_version' => EditorialPolicy::version(),
            'fact_check' => $fc,
            'review_status' => $fc['verdict'],
            'ai_usage' => $ai->usage(),
            'web_researched_at' => now(),
        ])->save();

        try {
            $this->seo->optimizePost($post);
        } catch (\Throwable) {
        }

        Log::info("Editorial pipeline #{$post->id}: {$fc['verdict']} ({$fc['critical']} critical, {$ai->usage()['calls']} AI calls)");

        return null;
    }

    /**
     * Stages 2-9 for a PUBLISHED article: produce a revision draft for editorial
     * approval. The live article is not modified.
     */
    public function reviseExisting(Post $post): PostRevision
    {
        return $this->reviseWithSources($post, $this->gatherSources($post, 5), new AiClient);
    }

    /**
     * The revision pipeline on a given, already-fetched source list (evidence packets chosen
     * by an editor instead of found by search). $opts: note (editor note for the writer),
     * opinion (labeled opinion column), skip_original_check (saves one paid request).
     */
    public function reviseWithSources(Post $post, array $sources, AiClient $ai, array $opts = []): PostRevision
    {
        // The exact publication moment, so "later" developments can be told apart.
        $asOf = optional($post->published_at)->copy()?->setTimezone(config('app.display_timezone', 'America/New_York'))->format('l, F j, Y, g:i A T');
        $current = trim(preg_replace("/\n{3,}/", "\n\n", strip_tags(str_replace(['</p>', '</h2>'], ["</p>\n\n", "</h2>\n"], (string) $post->body))));

        $make = fn (string $status, array $extra = []) => PostRevision::create($extra + [
            'post_id' => $post->id,
            'status' => $status,
            'policy_version' => EditorialPolicy::version(),
            'title' => $post->title,
            'body' => (string) $post->body,
            'snapshot' => PostRevision::snapshotOf($post),
            'ai_usage' => $ai->usage(),
        ]);

        if (! $sources) {
            return $make('needs_research', ['change_log' => ['summary' => 'No source evidence could be retrieved to verify this article.']]);
        }
        $sources = self::markLaterSources($sources, $post->published_at);

        // Identify inaccuracies in the PUBLISHED version first (stage 5 on the original).
        $originalCheck = empty($opts['skip_original_check'])
            ? $this->checker->check($ai, $post->title, (string) $post->body, $sources, $asOf)
            : $this->checker->check(new AiClient(maxCalls: 0), $post->title, (string) $post->body, $sources, $asOf);

        $draft = $this->rewriter->writeArticle($ai, 'revision', [
            'title' => $post->title,
            'category' => $post->category?->name ?? 'News',
            'sources' => $sources,
            'as_of' => $asOf,
            'existing' => $current,
            'known_issues' => FactChecker::numberedIssues($originalCheck)
                . (filled($opts['note'] ?? null) ? "
EDITOR NOTE: " . $opts['note'] : ''),
            'opinion' => (bool) ($opts['opinion'] ?? false),
            'allow_needs_reporting' => true,
            'update_date' => self::latestSourceDate($sources),
        ]);
        if (is_array($draft) && ! empty($draft['needs_reporting'])) {
            return $make('needs_research', [
                'fact_check' => $originalCheck,
                'change_log' => ['summary' => 'Needs reporting: ' . implode(' ', $draft['reporting_gaps']), 'original_issues' => $originalCheck['issues']],
            ]);
        }
        if (! $draft) {
            return $make('needs_rewrite', [
                'fact_check' => $originalCheck,
                'change_log' => ['summary' => 'The writer produced nothing usable for this article.', 'original_issues' => $originalCheck['issues']],
            ]);
        }

        [$draft, $fc] = $this->checkAndFix($ai, $draft, $sources, $asOf, $post);

        $probe = new Post(['title' => $draft['headline'], 'body' => $draft['body']]);
        $probe->id = $post->id;                 // so it isn't compared with its own live version
        $gate = PublishGate::originality($probe);
        $status = match (true) {
            $fc['verdict'] === 'ready' && ! $gate => 'pending_review',
            $fc['verdict'] === 'ready' => 'needs_rewrite',
            default => $fc['verdict'],
        };

        return $make($status, [
            'story_type' => $draft['story_type'],
            'title' => $draft['headline'] ?: $post->title,
            'excerpt' => $draft['excerpt'] ?: $post->excerpt,
            'body' => $draft['body'],
            'meta_title' => $draft['meta_title'] ?: $post->meta_title,
            'meta_description' => $draft['meta_description'] ?: $post->meta_description,
            'takeaways' => \App\Support\ArticleSections::takeaways($draft['takeaways'] ?? []),
            'sources' => $this->sourceRecords($sources, $fc),
            'fact_check' => $fc + ['originality' => $gate],
            'change_log' => [
                'summary' => $draft['change_summary'],
                'corrections' => self::verifiedCorrections($draft['corrections'], $originalCheck),
                'original_issues' => $originalCheck['issues'],
            ],
            'ai_usage' => $ai->usage(),
        ]);
    }

    /**
     * Guard for edits to an already-PUBLISHED article (freshness merges): accept
     * the new body only if it passes the independent fact-check and originality.
     *
     * @param array<int,array{name:string,url:string,text:string}> $sources
     */
    public function acceptUpdate(Post $post, string $newBody, array $sources): bool
    {
        $ai = new AiClient;
        $fc = $this->checker->check($ai, $post->title, $newBody, $sources);
        $probe = clone $post;
        $probe->body = $newBody;
        $ok = $fc['verdict'] === 'ready' && ! PublishGate::originality($probe);
        Log::info("Editorial update guard #{$post->id}: " . ($ok ? 'accepted' : "rejected ({$fc['verdict']})"));

        return $ok;
    }

    /** Fix passes allowed after the first fact-check (stage 7 retry limit). */
    private const MAX_FIX_PASSES = 2;

    /**
     * Fact-check the draft; while it has fact-check findings (critical OR minor)
     * or breaks an originality rule, run a targeted fix pass and check again —
     * at most MAX_FIX_PASSES times. A pass that can't be produced stops the loop.
     *
     * @return array{0:array,1:array} [draft, fact_check]
     */
    private function checkAndFix(AiClient $ai, array $draft, array $sources, ?string $asOf, ?Post $post = null): array
    {
        $fc = $this->checker->check($ai, $draft['headline'], $draft['body'], $sources, $asOf);

        for ($pass = 1; $pass <= self::MAX_FIX_PASSES; $pass++) {
            $probe = new Post(['title' => $draft['headline'], 'body' => $draft['body']]);
            if ($post) {
                $probe->id = $post->id;
            }
            $wording = PublishGate::originality($probe);
            $fixable = in_array($fc['verdict'], ['ready', 'needs_rewrite'], true);   // needs_research can't be fixed by rewording

            if (! $fixable || (! $fc['issues'] && ! $wording)) {
                break;
            }

            // Blocking style rules first — at the end of a long list of minor
            // findings they were being ignored ("news reports identified ...").
            $feedback = '';
            foreach ($wording as $msg) {
                $feedback .= "- [MUST FIX — publishing rule] {$msg} Rewrite that sentence so the problem is gone entirely.\n";
            }
            $feedback .= FactChecker::feedback($fc);
            $fixed = $this->rewriter->writeArticle($ai, 'fix', [
                'title' => $draft['headline'],
                'sources' => $sources,
                'draft' => $draft['body'],
                'feedback' => trim($feedback),
            ]);
            if (! $fixed) {
                break;
            }
            $fixed['corrections'] = $draft['corrections'];            // keep the revision's correction list
            $fixed['change_summary'] = $draft['change_summary'];      // the reader-facing summary of the revision
            $draft = $fixed;
            $fc = $this->checker->check($ai, $draft['headline'], $draft['body'], $sources, $asOf);
        }

        return [$draft, $fc];
    }

    /**
     * PROPOSED corrections for the editor (never published without an editor
     * confirming each one — see PostRevision::apply). Filtered in code: keep only
     * those the writer tied to a CRITICAL finding on the published version where
     * the sources establish the right fact. A removed unsupported detail,
     * rewording or added attribution is an edit, not a correction.
     *
     * @param array<int,array{issue:int,text:string}> $claimed
     * @return array<int,string>
     */
    public static function verifiedCorrections(array $claimed, array $originalCheck): array
    {
        $allowed = array_flip(FactChecker::correctableIssues($originalCheck));
        $out = [];
        $seen = [];
        foreach ($claimed as $c) {
            $n = (int) ($c['issue'] ?? 0);
            $text = (string) ($c['text'] ?? '');
            // "The sources do not support X" means X was removed, not that X was wrong.
            $isRemoval = (bool) preg_match('/\b(do(es)? not support|not supported|unsupported|could not be (verified|confirmed)|no (provided )?source)\b/i', $text);
            // The same correction cited against several findings is one correction.
            $key = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
            if (isset($allowed[$n]) && ! $isRemoval && ! isset($out[$n]) && ! isset($seen[$key])) {
                $out[$n] = $text;
                $seen[$key] = true;
            }
        }

        return array_values($out);
    }

    /**
     * The story's own source (re-fetched) plus independent coverage, ranked by
     * source preference, as [{name,url,title,published_at,accessed_at,tier,text}].
     */
    public function gatherSources(Post $post, int $max): array
    {
        $out = [];
        $seen = [];
        $add = function (string $url, ?string $fallbackText = null) use (&$out, &$seen) {
            $host = Str::lower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));
            if ($host === '' || isset($seen[$host])) {
                return;
            }
            if ($this->researcher->blockedHost($host)) {
                return;   // blogs / platforms never count as sources
            }
            $page = $this->fetcher->extract($url);
            $text = (string) ($page['text'] ?? '') ?: (string) $fallbackText;
            if (str_word_count($text) < 120 || WebResearcher::isCopyOf((string) ($page['title'] ?? ''), $text, $out)) {
                return;   // too thin, or a republished copy of a source we already have
            }
            $seen[$host] = true;
            $out[] = ['text' => $text] + $this->researcher->sourceRecord($url, $page);
        };

        // The originating report first (fall back to our draft's own text if the page is gone).
        if (filled($post->source_url)) {
            $add($post->source_url, $post->status === 'draft' ? strip_tags((string) $post->body) : null);
        }
        // Sources already recorded on the post.
        foreach ((array) $post->sources as $s) {
            if (count($out) >= $max) {
                break;
            }
            if (is_array($s) && filled($s['url'] ?? null)) {
                $add($s['url']);
            }
        }
        // Independent coverage from the open web.
        if (count($out) < $max && $this->researcher->enabled()) {
            foreach ($this->researcher->research($post->title, array_keys($seen), $max - count($out) + 2) as $r) {
                if (count($out) >= $max) {
                    break;
                }
                $host = Str::lower(preg_replace('/^www\./', '', (string) parse_url($r['url'], PHP_URL_HOST)));
                if (! isset($seen[$host]) && ! WebResearcher::isCopyOf((string) ($r['title'] ?? ''), (string) $r['text'], $out)) {
                    $seen[$host] = true;
                    $out[] = $r;
                }
            }
        }

        // Preferred sources first, but keep the originating report at the top.
        $first = array_shift($out);
        usort($out, fn ($a, $b) => ($a['tier'] ?? 2) <=> ($b['tier'] ?? 2));

        // One shrink for writer, fix passes and fact-checker: they all judge the same excerpts.
        return SourcePacket::prepare(array_values(array_filter(array_merge([$first], $out))), $post->title);
    }

    /**
     * Evidence for checking an edit to a published article: the pages of the
     * sources already on record (re-fetched, cached) plus any new ones.
     *
     * @param array<int,array{name:string,url:string,text:string}> $extra
     */
    public function recordedEvidence(Post $post, array $extra = []): array
    {
        $out = [];
        $urls = array_filter(array_merge([$post->source_url], array_map(fn ($s) => is_array($s) ? ($s['url'] ?? null) : null, (array) $post->sources)));
        foreach (array_unique($urls) as $url) {
            $page = $this->fetcher->extract($url);
            if (str_word_count((string) ($page['text'] ?? '')) >= 120) {
                $out[] = ['text' => $page['text']] + $this->researcher->sourceRecord($url, $page);
            }
        }

        return SourcePacket::prepare(array_merge($out, $extra), $post->title);
    }

    /**
     * Flag sources published or last updated AFTER our article went out: facts
     * found only in them are later developments (Update paragraph only). This is
     * data, not a prompt hope — the writer and fact-checker both see the label.
     */
    public static function markLaterSources(array $sources, $publishedAt): array
    {
        if (! $publishedAt) {
            return $sources;
        }
        $pub = \Illuminate\Support\Carbon::parse($publishedAt);
        foreach ($sources as $i => $s) {
            $later = false;
            foreach (['published_at', 'modified_at'] as $f) {
                try {
                    $later = $later || (filled($s[$f] ?? null) && \Illuminate\Support\Carbon::parse($s[$f])->gt($pub));
                } catch (\Throwable) {
                }
            }
            $sources[$i]['later'] = $later;
        }

        return $sources;
    }

    /** Date for an "Update" label: the latest publish/update time among later sources (NY time). */
    public static function latestSourceDate(array $sources): ?string
    {
        $latest = null;
        foreach ($sources as $s) {
            if (empty($s['later'])) {
                continue;
            }
            foreach (['published_at', 'modified_at'] as $f) {
                try {
                    $d = filled($s[$f] ?? null) ? \Illuminate\Support\Carbon::parse($s[$f]) : null;
                } catch (\Throwable) {
                    $d = null;
                }
                if ($d && (! $latest || $d->gt($latest))) {
                    $latest = $d;
                }
            }
        }

        return $latest?->setTimezone(config('app.display_timezone', 'America/New_York'))->format('F j, Y');
    }

    /** Source records for storage: no article text, plus the claims each supports. */
    private function sourceRecords(array $sources, array $fc): array
    {
        return array_values(array_map(function ($s) use ($fc) {
            $rec = array_diff_key($s, ['text' => true]);
            $rec['supports'] = $fc['source_support'][$s['url']] ?? [];

            return $rec;
        }, $sources));
    }
}
