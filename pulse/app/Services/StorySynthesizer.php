<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Research-first synthesis for ONE queued draft: search the open web (Brave) for
 * other outlets covering the same story, then write the whole article once from
 * the primary report plus those sources (multi-source, no outlet names in the
 * body, ending with a labeled "Our Take").
 *
 * Shared by posts:synthesize (regular queue) and BreakingPublisher (fast lane).
 * Cost-safe: it only spends the AI call when enough extra sources were found.
 */
class StorySynthesizer
{
    public function __construct(
        private WebResearcher $researcher,
        private Rewriter $rewriter,
        private ArticleFetcher $fetcher,
        private SeoOptimizer $seo,
    ) {}

    public function enabled(): bool
    {
        return $this->researcher->enabled();
    }

    /**
     * Synthesize the draft. Returns null on success, or a short reason it was
     * left as is (not enough sources yet, AI returned nothing, busy, …).
     *
     * @param  array<int,array{name:string,url:string}>|null  $foundOut  sources found (for --dry)
     */
    public function synthesize(Post $post, int $minSources, bool $dry = false, ?array &$foundOut = null): ?string
    {
        if (! $this->enabled()) {
            return 'web research disabled (no web-search key)';
        }

        // posts:synthesize and the breaking fast lane can reach the same draft at once.
        $lock = Cache::lock("synthesize-post:{$post->id}", 300);
        if (! $dry && ! $lock->get()) {
            return 'already being synthesized';
        }

        try {
            $cap = (int) Setting::get('max_sources_per_post', 4);
            $neededExtra = max(1, $minSources - 1); // the primary report counts as one

            // Primary source text: re-fetch the original article; fall back to the
            // existing draft body if the fetch fails.
            $primary = '';
            try {
                $primary = (string) ($this->fetcher->extract($post->source_url)['text'] ?? '');
            } catch (\Throwable) {
            }
            if (blank($primary)) {
                $primary = strip_tags((string) $post->body);
            }

            // Outlets already attached (so we don't fetch the same one twice).
            $sources = is_array($post->sources) ? $post->sources : [];
            if (empty($sources) && filled($post->source_url)) {
                $sources = [['name' => $post->source_name, 'url' => $post->source_url]];
            }
            $hosts = collect($sources)
                ->map(fn ($s) => Str::lower((string) parse_url($s['url'] ?? '', PHP_URL_HOST)))
                ->map(fn ($h) => Str::startsWith($h, 'www.') ? substr($h, 4) : $h)
                ->filter()->all();

            $extra = $this->researcher->research($post->title, $hosts, max(0, $cap - count($sources)));
            $foundOut = $extra;

            if (count($extra) < $neededExtra) {
                return 'only ' . count($extra) . " extra source(s) found (need {$neededExtra})";
            }
            if ($dry) {
                return null;
            }

            $result = $this->rewriter->synthesize($post->title, $primary, $extra);
            if (! $result || blank($result['body'] ?? null)) {
                return 'synthesis produced nothing';
            }

            foreach ($extra as $src) {
                $sources[] = ['name' => $src['name'], 'url' => $src['url']];
            }

            $post->forceFill([
                'body' => $result['body'],
                'sources' => array_values(array_slice($sources, 0, $cap)),
                'web_researched_at' => now(),
            ])->save();

            try {
                $this->seo->optimizePost($post);
            } catch (\Throwable) {
            }

            return null;
        } finally {
            if (! $dry) {
                $lock->release();
            }
        }
    }
}
