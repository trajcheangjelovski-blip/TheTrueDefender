<?php

namespace App\Services;

use App\Models\PageSeo;
use App\Models\Post;
use Illuminate\Support\Facades\Http;

/**
 * Auto SEO optimization: runs the AI analysis, APPLIES the suggested meta
 * (title / description / focus keyword), then re-scores so the stored score
 * reflects the optimized state.
 *
 * $overwrite = false → only blank meta fields are filled (safe for bulk runs,
 * never clobbers hand-written meta). true → AI suggestions replace existing.
 */
class SeoOptimizer
{
    public function __construct(
        private SeoAnalyzer $analyzer,
        private LinkEnricher $links,
    ) {}

    /** Optimize a post. Returns the final analysis (after links + meta applied). */
    public function optimizePost(Post $post, bool $overwrite = false): array
    {
        // Add validated internal + authoritative external links first, so the
        // score reflects them (idempotent — skips already-linked posts).
        try {
            $this->links->enrichPost($post);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Link enrichment failed for post {$post->id}: " . $e->getMessage());
        }

        $url = route('post.show', $post);

        $analysis = $this->analyzer->analyze(
            $post->title, $post->body, $post->meta_title,
            $post->meta_description, $post->focus_keyword, $url,
        );

        $changed = $this->applyMeta($post, $analysis['suggested'] ?? [], $overwrite);

        // Re-score with the new meta in place so the stored score is honest.
        if ($changed) {
            $analysis = $this->analyzer->analyze(
                $post->title, $post->body, $post->meta_title,
                $post->meta_description, $post->focus_keyword, $url,
            );
        }

        $post->forceFill([
            'seo_score' => $analysis['score'],
            'seo_analysis' => $analysis,
            'seo_analyzed_at' => now(),
        ])->saveQuietly();

        return $analysis;
    }

    /** Optimize a static page (fetches the live rendered HTML for context). */
    public function optimizePage(PageSeo $page, bool $overwrite = false): array
    {
        $url = url($page->path);
        $body = $this->renderedBody($url);
        $title = $page->meta_title ?: $page->label;

        $analysis = $this->analyzer->analyze(
            $title, $body, $page->meta_title, $page->meta_description, $page->focus_keyword, $url,
        );

        $changed = $this->applyMeta($page, $analysis['suggested'] ?? [], $overwrite);

        if ($changed) {
            $analysis = $this->analyzer->analyze(
                $page->meta_title ?: $page->label, $body,
                $page->meta_title, $page->meta_description, $page->focus_keyword, $url,
            );
        }

        $page->forceFill([
            'seo_score' => $analysis['score'],
            'seo_analysis' => $analysis,
            'seo_analyzed_at' => now(),
        ])->save();

        return $analysis;
    }

    /** Fetch a page's rendered HTML body for analysis (null when unreachable). */
    public function renderedBody(string $url): ?string
    {
        try {
            $html = Http::timeout(15)->get($url)->body();
            if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
                return $m[1];
            }

            return $html;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Apply suggested meta to a model. Returns true when anything changed. */
    private function applyMeta(Post|PageSeo $model, array $suggested, bool $overwrite): bool
    {
        $changed = false;

        foreach (['meta_title', 'meta_description', 'focus_keyword'] as $field) {
            $suggestion = trim((string) ($suggested[$field] ?? ''));
            if ($suggestion === '') {
                continue;
            }
            if ($overwrite || blank($model->{$field})) {
                if ($model->{$field} !== $suggestion) {
                    $model->{$field} = $suggestion;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $model->saveQuietly();
        }

        return $changed;
    }
}
