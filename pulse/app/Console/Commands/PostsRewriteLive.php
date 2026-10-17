<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ArticleFetcher;
use App\Services\Rewriter;
use App\Services\SeoOptimizer;
use App\Support\PublishGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Re-write PUBLISHED news articles with the current writer rules: a rotating
 * article structure, story-specific headings, no opening that restates the
 * headline, no repeated or reused sentences, and a story-specific "Our Take".
 *
 * Each article is re-synthesized from its own recorded sources (re-fetched where
 * still available) plus its current text, so no facts are lost. Title, URL,
 * image and publish date are untouched. A rewrite only replaces the old body
 * when it passes PublishGate (incl. the originality checks); one retry, else the
 * old body stays. All current bodies are backed up to storage/app/backups first.
 *
 *   php artisan posts:rewrite-live --dry        # list what would be rewritten
 *   php artisan posts:rewrite-live              # rewrite all published news posts
 *   php artisan posts:rewrite-live --ids=1,2,3  # only these posts
 */
class PostsRewriteLive extends Command
{
    protected $signature = 'posts:rewrite-live
        {--ids= : Comma-separated post IDs (default: all published news posts)}
        {--dry : List what would be rewritten, change nothing}';

    protected $description = 'Re-write published news posts with the current writer rules (varied structure, no repetition). Keeps title/URL/image/date; backs up first.';

    private const ORIGINALITY = ['headline-echo', 'repeated-sentence', 'reused-sentence', 'generic-heading'];

    public function handle(Rewriter $rewriter, ArticleFetcher $fetcher, SeoOptimizer $seo): int
    {
        $query = Post::where('status', 'published')->whereNotNull('source_url')->orderBy('published_at');
        if (filled($ids = $this->option('ids'))) {
            $query->whereIn('id', array_map('intval', explode(',', $ids)));
        }
        $posts = $query->get();

        if ($this->option('dry')) {
            foreach ($posts as $p) {
                $fails = array_intersect(array_keys(PublishGate::failures($p)), self::ORIGINALITY);
                $this->line("#{$p->id} " . ($fails ? implode(',', $fails) : 'ok') . '  ' . Str::limit($p->title, 60));
            }
            $this->info("[DRY] {$posts->count()} post(s) would be rewritten.");

            return self::SUCCESS;
        }

        $path = 'backups/rewrite-live-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($path, $posts->map->only(['id', 'slug', 'title', 'body', 'sources'])->values()->toJson(JSON_PRETTY_PRINT));
        $this->info("Backed up {$posts->count()} body/bodies to storage/app/{$path}");

        $ok = $kept = 0;
        foreach ($posts as $post) {
            [$primary, $extra] = $this->sourceTexts($post, $fetcher);
            $why = 'synthesis produced nothing';

            for ($try = 1; $try <= 2; $try++) {
                $result = $rewriter->synthesize($post->title, $primary, $extra);
                if (! $result || blank($result['body'] ?? null)) {
                    continue;
                }
                $candidate = clone $post;
                $candidate->body = $result['body'];
                if (! $fails = PublishGate::failures($candidate)) {
                    $post->forceFill(['body' => $result['body']])->saveQuietly();
                    try {
                        $seo->optimizePost($post);   // re-add internal links + refresh score
                    } catch (\Throwable) {
                    }
                    $this->info("  ✓ #{$post->id} rewritten (" . str_word_count(strip_tags($result['body'])) . ' words)  ' . Str::limit($post->title, 50));
                    $ok++;
                    continue 2;
                }
                $why = implode('; ', array_values($fails));
            }

            $this->warn("  · #{$post->id} kept as is — {$why}  " . Str::limit($post->title, 40));
            $kept++;
        }

        $this->info("Done — rewritten {$ok}, kept {$kept}. Backup: storage/app/{$path}");

        return self::SUCCESS;
    }

    /**
     * The article's own sources, re-fetched, plus its current text (so no fact is
     * lost if a source page changed or is gone).
     *
     * @return array{0:string,1:array<int,array{name:string,url:string,text:string}>}
     */
    private function sourceTexts(Post $post, ArticleFetcher $fetcher): array
    {
        $fetch = function (?string $url) use ($fetcher): string {
            try {
                return (string) ($fetcher->extract($url)['text'] ?? '');
            } catch (\Throwable) {
                return '';
            }
        };
        $host = fn (?string $u) => preg_replace('/^www\./', '', Str::lower((string) parse_url((string) $u, PHP_URL_HOST)));

        $current = trim(strip_tags(str_replace('</p>', "</p>\n", (string) $post->body)));
        $primary = $fetch($post->source_url);
        if (str_word_count($primary) < 120) {
            $primary = $current;
        }

        $extra = [];
        foreach ((array) $post->sources as $s) {
            $url = is_array($s) ? ($s['url'] ?? null) : null;
            if (! $url || $host($url) === $host($post->source_url)) {
                continue;
            }
            $text = $fetch($url);
            if (str_word_count($text) >= 120) {
                $extra[] = ['name' => (string) ($s['name'] ?? ''), 'url' => $url, 'text' => $text];
            }
        }
        if ($primary !== $current) {
            $extra[] = ['name' => 'current article', 'url' => '', 'text' => $current];
        }

        return [$primary, $extra];
    }
}
