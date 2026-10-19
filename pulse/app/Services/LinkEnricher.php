<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Adds internal + authoritative external links to a post body using AI, then
 * VALIDATES every link so broken/hallucinated ones are impossible:
 *   - internal links must resolve to a real published post / category
 *   - external links must be English Wikipedia or a .gov site AND return 200
 * Anything that fails validation is unwrapped back to plain text.
 */
class LinkEnricher
{
    /** Max external links to HTTP-verify per post (keeps ingest bounded). */
    private const MAX_EXTERNAL_CHECKS = 5;

    /** Domains allowed for external links (authoritative references only). */
    private function externalAllowed(string $host): bool
    {
        $host = strtolower($host);

        return str_ends_with($host, 'wikipedia.org') || str_ends_with($host, '.gov');
    }

    /**
     * Enrich a post's body with validated links. No-op (returns false) if the
     * body already has internal links, or when there's nothing to add.
     */
    public function enrichPost(Post $post): bool
    {
        $body = (string) $post->body;
        if ($body === '') {
            return false;
        }

        // Already enriched? (idempotent — safe to call on re-optimize/backfill)
        if (preg_match('/<a\s[^>]*href=["\'][^"\']*\/(post|category)\//i', $body)) {
            return false;
        }

        set_time_limit(180);

        $candidates = $this->internalCandidates($post);
        $linked = $this->linkify($body, $candidates);
        // Link enrichment must not rewrite reporting, drop attribution or add
        // an appendix after the independent fact-check has already passed.
        if (\App\Support\PublicSourceSections::hasSection($linked)
            || $this->wording($linked) !== $this->wording($body)) {
            return false;
        }
        preg_match_all('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $body, $originalLinks, PREG_SET_ORDER);
        preg_match_all('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $linked, $linkedLinks, PREG_SET_ORDER);
        $key = fn ($a) => html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n" . $this->wording($a[3]);
        if (array_diff(array_map($key, $originalLinks), array_map($key, $linkedLinks))) {
            return false;
        }
        $clean = \App\Support\LinkQuality::unwrapWikipedia($this->sanitize($linked, $candidates, $body));

        if ($clean === $body || trim(strip_tags($clean)) === '') {
            return false;
        }

        $post->body = $clean;
        $post->saveQuietly();

        return true;
    }

    /** Real published posts we may link to (same category first, then recent). */
    private function internalCandidates(Post $post): array
    {
        $sameCat = Post::published()
            ->when($post->category_id, fn ($q) => $q->where('category_id', $post->category_id))
            ->whereKeyNot($post->id)
            ->latest('published_at')->take(6)->get(['title', 'slug']);

        $recent = Post::published()
            ->whereKeyNot($post->id)
            ->whereNotIn('id', $sameCat->pluck('id'))
            ->latest('published_at')->take(6)->get(['title', 'slug']);

        return $sameCat->concat($recent)
            ->map(fn (Post $p) => ['title' => $p->title, 'url' => '/post/' . $p->slug])
            ->take(10)->all();
    }

    /** Ask the AI to wrap existing phrases in links (no wording changes). */
    private function linkify(string $html, array $candidates): string
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return $html;
        }

        $list = collect($candidates)
            ->map(fn ($c) => "- {$c['title']} => {$c['url']}")
            ->implode("\n") ?: '(none available yet)';

        $system = <<<SYS
        You add hyperlinks to an existing HTML news article body. Follow EXACTLY:
        - Do NOT change, add, or remove any words. Only wrap phrases that already
          exist in the text with <a> tags.
        - INTERNAL links: add 2-4, using ONLY URLs from the provided list, on
          phrases relevant to that article. Never invent internal URLs.
        - EXTERNAL links: add up to 3 to authoritative references only — English
          Wikipedia (https://en.wikipedia.org/wiki/Article_Name) or official US
          government (.gov) pages — on notable named entities (people, agencies,
          places, laws). Use real, correctly spelled Wikipedia titles.
        - Never link the same phrase twice; don't over-link a paragraph.
        - Keep existing inline source links and attribution. Never append a Sources,
          References, Bibliography, research notes or other standalone source list.
        - Return the full body HTML with the <a> tags added.
        SYS;

        $user = "INTERNAL LINK OPTIONS (title => url):\n{$list}\n\nARTICLE BODY HTML:\n{$html}";

        try {
            $response = Http::withToken(trim($key))
                ->timeout(60)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'linked_body',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['body' => ['type' => 'string']],
                                'required' => ['body'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])
                ->throw();

            $body = data_get($response->json(), 'choices.0.message.content');
            $data = json_decode((string) $body, true);

            return is_array($data) && filled($data['body'] ?? null) ? $data['body'] : $html;
        } catch (\Throwable $e) {
            Log::warning('Link enrichment AI call failed: ' . $e->getMessage());

            return $html;
        }
    }

    /**
     * Validate every <a>: keep real internal + verified authoritative external,
     * unwrap everything else back to plain text.
     */
    public function sanitize(string $html, array $candidates, string $original = ''): string
    {
        // Existing attribution links are part of the checked article. Preserve
        // their exact markup even when a source site blocks an automated fetch.
        preg_match_all('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>.*?<\/a>/is', $original, $existing, PREG_SET_ORDER);
        $preserved = [];
        foreach ($existing as $link) {
            $url = html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~^https?://~i', $url)) {
                $preserved[$url . "\n" . $this->wording($link[0])] = $link[0];
            }
        }
        $allowedInternal = collect($candidates)->pluck('url')
            ->map(fn ($u) => rtrim($u, '/'))->flip();
        $externalChecks = 0;

        return preg_replace_callback(
            '/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            function (array $m) use ($allowedInternal, &$externalChecks, $preserved) {
                $href = html_entity_decode(trim($m[2]));
                $text = $m[3];
                if (isset($preserved[$href . "\n" . $this->wording($text)])) {
                    return $preserved[$href . "\n" . $this->wording($text)];
                }
                $path = parse_url($href, PHP_URL_PATH) ?? '';
                $host = parse_url($href, PHP_URL_HOST);

                // Internal (relative, or our own host) → must be a real page.
                $isInternal = $host === null || $host === parse_url(config('app.url'), PHP_URL_HOST);
                if ($isInternal) {
                    $path = '/' . ltrim(rtrim($path, '/'), '/');
                    if (preg_match('#^/post/([^/]+)$#', $path, $mm) && Post::where('slug', $mm[1])->exists()) {
                        return '<a href="/post/' . $mm[1] . '">' . $text . '</a>';
                    }
                    if (preg_match('#^/category/([^/]+)$#', $path, $mm) && Category::where('slug', $mm[1])->exists()) {
                        return '<a href="/category/' . $mm[1] . '">' . $text . '</a>';
                    }

                    return $text; // internal link to a nonexistent page → unwrap
                }

                // External → allowlisted domain AND returns HTTP 200.
                if ($this->externalAllowed($host) && $externalChecks < self::MAX_EXTERNAL_CHECKS) {
                    $externalChecks++;
                    if ($this->urlIsLive($href)) {
                        return '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
                    }
                }

                return $text; // disallowed or dead external → unwrap
            },
            $html,
        );
    }

    private function wording(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Quick liveness check (fail closed: any error/non-200 → treat as dead). */
    private function urlIsLive(string $url): bool
    {
        try {
            return Http::timeout(5)
                ->withHeaders(['User-Agent' => 'TheTrueDefender/1.0'])
                ->get($url)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
