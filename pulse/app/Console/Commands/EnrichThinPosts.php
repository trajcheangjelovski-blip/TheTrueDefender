<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use App\Services\ArticleFetcher;
use App\Services\ContentEnricher;
use App\Services\Rewriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('posts:enrich-thin {--min=500 : Word floor — posts under this are candidates} {--limit=25 : Max posts to process this run} {--order=oldest : oldest|views — which thin posts first} {--sleep=2 : Seconds to pause between AI calls} {--dry-run : Report what would change without writing}')]
#[Description('Re-fetch a thin post\'s original source and re-run the rewrite pipeline to deepen it (adds context/analysis), then refresh takeaways & FAQs. Preserves title/slug/URL. Reversible in spirit — always review a dry-run first.')]
class EnrichThinPosts extends Command
{
    public function handle(Rewriter $rewriter, ArticleFetcher $articles, ContentEnricher $enricher): int
    {
        $min = max(100, (int) $this->option('min'));
        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dry = (bool) $this->option('dry-run');

        // Require a real AI key — without it Rewriter falls back to a short stub,
        // which would REPLACE decent content with something worse. Refuse instead.
        $key = \App\Models\Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured — aborting so thin posts are not overwritten with stubs.');
            return self::FAILURE;
        }

        // Category options the rewrite may keep the piece in (mirror IngestService:
        // exclude Opinion, which is the reader-discussion section, not ingested news).
        $categories = Category::where('is_active', true)->whereNotIn('slug', ['opinion'])
            ->get(['id', 'slug', 'name']);
        $catList = $categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->all();

        // Pull published posts and filter to the thin ones in PHP (word count isn't
        // a column). Order so the most valuable get fixed first.
        $query = Post::published()->with('category');
        $query = $this->option('order') === 'views'
            ? $query->orderByDesc('views')
            : $query->orderBy('published_at'); // oldest first by default

        $thin = $query->get(['id', 'title', 'slug', 'excerpt', 'body', 'takeaways', 'faqs', 'category_id', 'source_name', 'source_url', 'views', 'published_at'])
            ->filter(fn ($p) => str_word_count(strip_tags((string) $p->body)) < $min)
            ->take($limit)
            ->values();

        if ($thin->isEmpty()) {
            $this->info("No published posts under {$min} words. Nothing to do.");
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Enriching {$thin->count()} thin post(s) (< {$min} words)…");
        $this->newLine();

        $improved = 0; $skippedShort = 0; $skippedNoSource = 0; $errors = 0;

        foreach ($thin as $i => $post) {
            $oldWords = str_word_count(strip_tags((string) $post->body));
            $label = '[' . ($i + 1) . '/' . $thin->count() . '] #' . $post->id . ' ' . Str::limit($post->title, 60);

            try {
                // Prefer the ORIGINAL source article (best material for a deep,
                // grounded rewrite). If it's gone/paywalled, fall back to the
                // post's own body so the model still expands with context.
                $full = null;
                if (filled($post->source_url)) {
                    $page = $articles->extract($post->source_url);
                    $full = $page['text'] ?? null;
                }
                $usedSource = filled($full);
                if (! $usedSource) {
                    $full = strip_tags((string) $post->body);
                    $skippedNoSource++; // count it, but still try to deepen from existing body
                }

                $item = [
                    'title'     => $post->title,
                    'summary'   => (string) ($post->excerpt ?: ''),
                    'link'      => (string) ($post->source_url ?: ''),
                    'full_text' => $full, // triggers the Rewriter's "substantial article" length rule
                ];

                $rw = $rewriter->rewrite($item, $post->category?->name ?? 'News', $post->source_name ?: 'source', $catList);
                $newBody = (string) ($rw['body'] ?? '');
                $newWords = str_word_count(strip_tags($newBody));

                // Only accept a genuine improvement: clears the floor AND is
                // meaningfully longer than before. Otherwise leave the post as-is.
                if ($newWords < $min || $newWords < $oldWords + 120) {
                    $this->line("  ✗ {$label} — result {$newWords}w (was {$oldWords}w) — skipped (not a clear improvement)");
                    $skippedShort++;
                    if ($sleep && $i < $thin->count() - 1) sleep($sleep);
                    continue;
                }

                $newExcerpt = trim((string) ($rw['excerpt'] ?? '')) ?: $post->excerpt;

                // Refresh takeaways + FAQs to match the new, deeper body.
                $en = $enricher->enrich($post->title, $newBody);

                if ($dry) {
                    $src = $usedSource ? 'source' : 'body-only';
                    $this->line("  ✓ {$label} — {$oldWords}w → {$newWords}w ({$src}) — WOULD update" . ($en['takeaways'] ? ' + refresh takeaways/faqs' : ''));
                    $improved++;
                } else {
                    $post->body = $newBody;
                    $post->excerpt = $newExcerpt;
                    if (! empty($en['takeaways'])) $post->takeaways = $en['takeaways'];
                    if (! empty($en['faqs'])) $post->faqs = $en['faqs'];
                    $post->save(); // title & slug untouched → URL preserved
                    $src = $usedSource ? 'source' : 'body-only';
                    $this->line("  ✓ {$label} — {$oldWords}w → {$newWords}w ({$src}) — updated");
                    $improved++;
                }
            } catch (\Throwable $e) {
                $this->line("  ! {$label} — error: " . Str::limit($e->getMessage(), 100));
                $errors++;
            }

            if ($sleep && $i < $thin->count() - 1) {
                sleep($sleep);
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY RUN] ' : '') . "Done — improved: {$improved}, skipped (too short/no gain): {$skippedShort}, source unavailable: {$skippedNoSource}, errors: {$errors}");
        if ($dry) {
            $this->comment('This was a dry run. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
