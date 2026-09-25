<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\ArticleSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * READ-ONLY editorial audit. Scans published posts and writes a review list of
 * issues a human editor should judge — it changes NOTHING. Flags:
 *   - outreach : implies WE sought comment ("did not respond to a request for comment")
 *   - ai-tell  : reveals it rewrote a single source ("the new report says")
 *   - thin     : body under the word floor
 *   - no-source: no source_url and no sources[] recorded
 *   - placeholder: leftover token like [[LINK]] (also fixable via content:scrub-placeholders)
 *   - duplicate: near-identical headline to another post within a few days
 *
 * Output: a CSV + JSON under storage/app/reports/ plus a console summary.
 */
class PostsReviewFlags extends Command
{
    protected $signature = 'posts:review-flags
        {--min-words=400 : Word count below which a post is flagged "thin"}
        {--days=4 : Window for treating similar headlines as possible duplicates}';

    protected $description = 'Read-only: list published articles needing editorial review (outreach claims, AI tells, thin, duplicates).';

    /** Phrases that imply first-hand outreach this newsroom does not do. */
    private const OUTREACH = [
        'did not respond to a request for comment',
        'did not respond to requests for comment',
        'did not immediately respond to a request for comment',
        'not respond to a request for comment by publication',
        'not respond to requests for comment by publication',
        'we reached out',
        'we contacted',
        'in a statement to the true defender',
        'told the true defender',
    ];

    /** AI tells that expose a thin single-source rewrite. */
    private const AI_TELLS = [
        'the new report says',
        'the new report adds',
        'the report says',
        'the report adds',
        'the report also says',
        'according to the report',
        'the article says',
        'the source article',
    ];

    public function handle(): int
    {
        $minWords = (int) $this->option('min-words');
        $days = (int) $this->option('days');

        $posts = Post::query()->where('status', 'published')
            ->orderBy('published_at')
            ->get(['id', 'slug', 'title', 'body', 'excerpt', 'social_text', 'takeaways', 'faqs', 'source_url', 'sources', 'published_at']);

        $rows = [];

        foreach ($posts as $post) {
            $flags = [];
            $plain = mb_strtolower(strip_tags((string) $post->body));
            $words = str_word_count(strip_tags((string) $post->body));

            foreach (self::OUTREACH as $needle) {
                if (str_contains($plain, $needle)) {
                    $flags['outreach'] = true;
                    break;
                }
            }
            foreach (self::AI_TELLS as $needle) {
                if (str_contains($plain, $needle)) {
                    $flags['ai-tell'] = ($flags['ai-tell'] ?? 0) + 1;
                }
            }
            if ($words < $minWords) {
                $flags['thin'] = $words;
            }
            $hasSources = filled($post->source_url) || (is_array($post->sources) && count($post->sources));
            if (! $hasSources) {
                $flags['no-source'] = true;
            }
            if (ArticleSanitizer::hasPlaceholder($post->body) || ArticleSanitizer::hasPlaceholder($post->excerpt)) {
                $flags['placeholder'] = true;
            }

            if ($flags) {
                $rows[] = [
                    'id' => $post->id,
                    'url' => '/post/' . $post->slug,
                    'title' => $post->title,
                    'published' => optional($post->published_at)->toDateString(),
                    'words' => $words,
                    'flags' => $flags,
                ];
            }
        }

        // Possible duplicates: near-identical headlines within N days.
        $dupes = $this->findDuplicates($posts, $days);

        $this->render($rows, $dupes);

        $stamp = now()->format('Ymd-His');
        Storage::disk('local')->put("reports/editorial-review-{$stamp}.json", json_encode([
            'generated_at' => now()->toIso8601String(),
            'flagged' => $rows,
            'possible_duplicates' => $dupes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put("reports/editorial-review-{$stamp}.csv", $this->csv($rows, $dupes));

        $this->info("Wrote storage/app/private/reports/editorial-review-{$stamp}.csv (and .json).");

        return self::SUCCESS;
    }

    /** Group posts whose normalized headline token-sets overlap heavily and close in time. */
    private function findDuplicates($posts, int $days): array
    {
        $stop = ['the', 'a', 'an', 'of', 'to', 'in', 'on', 'for', 'and', 'with', 'as', 'at', 'by', 'is', 'from', 'over', 'after'];
        $sets = [];
        foreach ($posts as $p) {
            $tokens = collect(preg_split('/\W+/', mb_strtolower($p->title)))
                ->filter(fn ($t) => $t !== '' && ! in_array($t, $stop, true))->unique()->values()->all();
            $sets[] = ['post' => $p, 'tokens' => $tokens];
        }

        $pairs = [];
        $n = count($sets);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $sets[$i]; $b = $sets[$j];
                if (! $a['post']->published_at || ! $b['post']->published_at) {
                    continue;
                }
                if ($a['post']->published_at->diffInDays($b['post']->published_at) > $days) {
                    continue;
                }
                $inter = count(array_intersect($a['tokens'], $b['tokens']));
                $union = count(array_unique(array_merge($a['tokens'], $b['tokens']))) ?: 1;
                $jaccard = $inter / $union;
                if ($jaccard >= 0.5) {
                    $pairs[] = [
                        'similarity' => round($jaccard, 2),
                        'a' => ['url' => '/post/' . $a['post']->slug, 'title' => $a['post']->title, 'published' => $a['post']->published_at->toDateString()],
                        'b' => ['url' => '/post/' . $b['post']->slug, 'title' => $b['post']->title, 'published' => $b['post']->published_at->toDateString()],
                    ];
                }
            }
        }

        usort($pairs, fn ($x, $y) => $y['similarity'] <=> $x['similarity']);

        return $pairs;
    }

    private function render(array $rows, array $dupes): void
    {
        $this->info('Flagged ' . count($rows) . ' post(s) for review:');
        foreach ($rows as $r) {
            $tags = collect($r['flags'])->map(fn ($v, $k) => is_bool($v) ? $k : "{$k}={$v}")->implode(', ');
            $this->line("  {$r['url']}  [{$tags}]  — " . Str::limit($r['title'], 60));
        }

        $this->newLine();
        $this->info('Possible duplicate clusters: ' . count($dupes));
        foreach ($dupes as $d) {
            $this->line("  ~{$d['similarity']}  {$d['a']['url']}  <=>  {$d['b']['url']}");
        }
    }

    private function csv(array $rows, array $dupes): string
    {
        $out = "type,url,title,published,words,flags\n";
        foreach ($rows as $r) {
            $flags = collect($r['flags'])->map(fn ($v, $k) => is_bool($v) ? $k : "{$k}:{$v}")->implode(' ');
            $out .= 'flag,' . $this->cell($r['url']) . ',' . $this->cell($r['title']) . ',' . $r['published'] . ',' . $r['words'] . ',' . $this->cell($flags) . "\n";
        }
        foreach ($dupes as $d) {
            $out .= 'duplicate,' . $this->cell($d['a']['url'] . ' <=> ' . $d['b']['url']) . ',' . $this->cell($d['a']['title'] . ' | ' . $d['b']['title']) . ',,' . ',similarity:' . $d['similarity'] . "\n";
        }

        return $out;
    }

    private function cell(string $v): string
    {
        return '"' . str_replace('"', '""', $v) . '"';
    }
}
