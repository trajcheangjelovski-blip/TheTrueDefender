<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\EditorialPipeline;
use App\Services\FactChecker;
use App\Services\Rewriter;
use App\Support\EditorialPolicy;
use App\Support\PublishGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Bounded editorial experiments, completely separate from publication. */
class PostsPreviewWriting extends Command
{
    protected $signature = 'posts:preview-writing {post : Existing article ID}
        {--check : Make one additional independent fact-check call}
        {--feedback= : Specific editorial feedback for this preview only}
        {--packets= : JSON file [{url}] of exact sources for evidence packets (no web search). Revision mode: the published text is rewritten as of its publication time}
        {--opinion : Treat the article as a labeled opinion column (with --packets)}
        {--ledger= : Experiment budget ledger name; refuses requests beyond --max-total-calls / --max-total-usd across all runs}
        {--max-total-calls=4 : With --ledger: paid model requests allowed in total}
        {--max-total-usd=0.50 : With --ledger: hard USD ceiling in total}';

    protected $description = 'Private writing preview: reuse recorded research, one writing call, optional fact-check, no automatic retries, SEO, images or publication.';

    public function handle(EditorialPipeline $pipeline, Rewriter $writer, FactChecker $checker): int
    {
        $post = Post::findOrFail($this->argument('post'));
        if ($this->option('packets')) {
            return $this->fromPackets($post, $pipeline, $writer);
        }
        $model = Setting::get('openai_model', config('services.openai.model'));
        $checkModel = Setting::get('fact_check_model') ?: $model;
        $this->info("Preview writer: {$model}; fact-checker: {$checkModel}. No article will be saved or published.");

        // No new search: recordedEvidence reuses the normal page cache. Three
        // existing sources keep trials comparable and the input bounded.
        $sources = array_slice($pipeline->recordedEvidence($post), 0, 3);
        if (count($sources) < 2) {
            $this->error('Fewer than two recorded sources could be retrieved. Preview skipped.');
            return self::FAILURE;
        }
        $ai = new AiClient(maxCalls: $this->option('check') ? 2 : 1, maxCompletionTokens: 3000, maxInputBytes: 100000);
        $draft = $writer->writeArticle($ai, 'new', [
            'title' => $post->title, 'category' => $post->category?->name ?? 'News',
            'sources' => $sources,
            'feedback' => 'Apply the current editorial policy to this private style trial. Preserve verified facts and attribution. '
                . Str::limit((string) $this->option('feedback'), 1500, ''),
        ]);
        $check = $draft && $this->option('check')
            ? $checker->check($ai, $draft['headline'], $draft['body'], $sources, $post->published_at?->toIso8601String())
            : null;
        $probe = $draft ? new Post([
            'title' => $draft['headline'], 'body' => $draft['body'], 'category_id' => $post->category_id,
            'story_type' => $draft['story_type'], 'sources' => $sources,
            'fact_check' => $check, 'review_status' => $check['verdict'] ?? 'needs_research',
        ]) : null;
        if ($probe) {
            $probe->id = $post->id; // don't compare the preview with its own source article
        }
        $report = [
            'preview_only' => true, 'based_on_post' => $post->id, 'created_at' => now()->toIso8601String(),
            'policy_version' => EditorialPolicy::version(), 'model' => $model, 'fact_check_model' => $checkModel,
            'feedback' => $this->option('feedback'), 'draft' => $draft, 'fact_check' => $check,
            'quality_findings' => $probe ? PublishGate::originality($probe) : ['empty' => 'Writer returned no usable preview.'],
            'publish_gate_failures' => $probe ? PublishGate::failures($probe) : ['empty' => 'Writer returned no usable preview.'],
            'usage' => $ai->usage(), 'sources' => $sources,
        ];
        $path = 'editorial-previews/' . now()->format('Ymd-His') . '-' . Str::uuid() . '.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            $this->error('Private preview could not be saved.');
            return self::FAILURE;
        }
        $this->info('Private preview: ' . Storage::disk('local')->path($path));
        $usage = $ai->usage();
        $this->info("API usage: {$usage['calls']} calls, {$usage['prompt_tokens']} input tokens, {$usage['completion_tokens']} output tokens.");
        $this->info('Fact-check: ' . ($check['verdict'] ?? 'not run') . '; quality findings: ' . count($report['quality_findings']));
        $this->info('Automatic publish gate: ' . ($report['publish_gate_failures'] ? count($report['publish_gate_failures']) . ' blocker(s)' : 'passed'));

        return $draft ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Source-packet rewrite of a published article through the real writer (revision mode).
     * Never saves to the articles table. Free local checks run on the result; model requests
     * are bounded by the shared ledger.
     */
    private function fromPackets(Post $post, EditorialPipeline $pipeline, Rewriter $writer): int
    {
        $model = Setting::get('openai_model', config('services.openai.model'));
        $entries = json_decode((string) @file_get_contents((string) $this->option('packets')), true);
        if (! is_array($entries) || ! $entries) {
            $this->error('Packet file unreadable.');

            return self::FAILURE;
        }
        $fetcher = app(\App\Services\ArticleFetcher::class);
        $researcher = app(\App\Services\WebResearcher::class);
        $raw = [];
        $unfetched = [];
        foreach ($entries as $e) {
            if (filled($e['citation'] ?? null)) {   // editor-verified reference to a document the server cannot fetch
                $raw[] = ['name' => $e['publisher'] ?? 'reference', 'url' => $e['url'], 'title' => $e['title'] ?? null, 'published_at' => $e['published_at'] ?? null,
                    'accessed_at' => now()->toIso8601String(), 'kind' => 'citation_only', 'text' => $e['citation']];

                continue;
            }
            $page = $fetcher->extract($e['url']);
            if (str_word_count((string) ($page['text'] ?? '')) < 60) {
                $unfetched[] = $e['url'];

                continue;
            }
            $raw[] = ['text' => $page['text']] + $researcher->sourceRecord($e['url'], $page, $e['publisher'] ?? null);
        }
        $raw = EditorialPipeline::markLaterSources($raw, $post->published_at);
        $packets = \App\Services\SourcePacket::prepare($raw, $post->title . ' ' . (string) $this->option('feedback'));
        $assessment = \App\Services\SourcePacket::assess($packets, (bool) $this->option('opinion'));

        $ledgerPath = 'editorial-previews/ledger-' . ($this->option('ledger') ?: 'none') . '.json';
        $ledger = $this->option('ledger') && Storage::disk('local')->exists($ledgerPath)
            ? json_decode(Storage::disk('local')->get($ledgerPath), true)
            : ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'usd' => 0.0, 'runs' => []];
        $remainingCalls = (int) $this->option('max-total-calls') - $ledger['calls'];
        $remainingUsd = (float) $this->option('max-total-usd') - $ledger['usd'];

        $ai = new AiClient(maxCalls: max(0, min(1, $remainingCalls)), maxCompletionTokens: 4000, maxInputBytes: 90000, maxCostUsd: max(0.0, $remainingUsd));
        $asOf = $post->published_at->copy()->setTimezone('America/New_York')->format('l, F j, Y, g:i A T');
        $result = null;
        if ($assessment['sufficient'] && $remainingCalls > 0) {
            $result = $writer->writeArticle($ai, 'revision', [
                'title' => $post->title, 'category' => $post->category?->name ?? 'News', 'sources' => $packets,
                'as_of' => $asOf, 'opinion' => (bool) $this->option('opinion'), 'allow_needs_reporting' => true,
                'existing' => trim(preg_replace("/\n{3,}/", "\n\n", strip_tags(str_replace(['</p>', '</h2>'], ["</p>\n\n", "</h2>\n"], (string) $post->body)))),
                'known_issues' => '(No separate fact-check was run. Verify every claim of the published version against the packets; '
                    . 'the published version may be stale or wrong. Editor note: ' . Str::limit((string) $this->option('feedback'), 800, '') . ')',
                'update_date' => EditorialPipeline::latestSourceDate($packets),
            ]);
        }
        $draft = is_array($result) && empty($result['needs_reporting']) ? $result : null;
        $urls = array_column($packets, 'url');
        $hrefs = [];
        if ($draft) {
            preg_match_all('/href="([^"]+)"/', $draft['body'], $m);
            $hrefs = array_values(array_unique($m[1]));
        }
        $local = $draft ? [
            'exact_quotes' => \App\Support\ExactQuoteVerifier::findings($draft['body'], $packets),
            'named_entities' => \App\Support\NamedEntityVerifier::findings($draft['body'], $packets),
            'dates_not_in_packets' => \App\Support\DraftCheck::unsupportedDates($draft['body'], $packets),
            'no_inline_links' => \App\Support\DraftCheck::missingLinks($draft['body'], $packets),
            'links_not_in_packets' => array_values(array_diff($hrefs, $urls)),
            'wikipedia_links' => array_values(array_filter($hrefs, fn ($u) => str_contains($u, 'wikipedia.org'))),
        ] : null;

        $usage = $ai->usage();
        if ($this->option('ledger')) {
            $ledger['calls'] += $usage['calls'];
            $ledger['prompt_tokens'] += $usage['prompt_tokens'];
            $ledger['completion_tokens'] += $usage['completion_tokens'];
            $ledger['usd'] = round($ledger['usd'] + ($usage['test_cost_usd'] ?? 0), 5);
            $ledger['runs'][] = ['post' => $post->id, 'at' => now()->toIso8601String(), 'calls' => $usage['calls'], 'usage' => $usage];
            Storage::disk('local')->put($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT));
        }
        $report = [
            'preview_only' => true, 'writer_output' => true, 'based_on_post' => $post->id, 'created_at' => now()->toIso8601String(),
            'policy_version' => EditorialPolicy::version(), 'examples_version' => EditorialPolicy::examplesVersion(), 'model' => $model,
            'as_of' => $asOf, 'editor_note' => $this->option('feedback'), 'opinion' => (bool) $this->option('opinion'),
            'assessment' => $assessment, 'unfetched_urls' => $unfetched,
            'result' => $draft ?: $result, 'needs_reporting' => is_array($result) && ! empty($result['needs_reporting']),
            'local_checks' => $local, 'usage' => $usage, 'ledger' => $ledger, 'packets' => $packets,
            'calls_remaining_before_run' => $remainingCalls, 'usd_remaining_before_run' => $remainingUsd,
        ];
        $path = 'editorial-previews/' . now()->format('Ymd-His') . "-post{$post->id}-packets.json";
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Report: ' . Storage::disk('local')->path($path));
        $this->info('Packets: ' . count($packets) . ' (unfetched: ' . count($unfetched) . '); assessment: ' . ($assessment['sufficient'] ? 'sufficient' : 'needs reporting'));
        $this->info("API usage: {$usage['calls']} calls, {$usage['prompt_tokens']} in, {$usage['completion_tokens']} out, \$" . ($usage['test_cost_usd'] ?? 0));

        return self::SUCCESS;
    }
}
