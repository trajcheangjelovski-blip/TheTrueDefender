<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Web research: given a story, search the OPEN WEB for other outlets' coverage,
 * pull the top independent reports, and return them as extra sources to
 * synthesize. Provider-configurable (Brave Search or Google Programmable Search);
 * a no-op when no search key is set, so it is always safe to call.
 */
class WebResearcher
{
    /** Hosts we never treat as a news source (social, video, aggregators, self). */
    private const BLOCK = [
        'youtube.com', 'youtu.be', 'twitter.com', 'x.com', 'facebook.com', 'instagram.com',
        'tiktok.com', 'reddit.com', 'pinterest.com', 'linkedin.com', 'threads.net',
        'wikipedia.org', 'msn.com', 'news.google.com', 'flipboard.com', 'yahoo.com',
    ];

    public function __construct(private ArticleFetcher $articles) {}

    /** Is web research configured (a provider key is present)? */
    public function enabled(): bool
    {
        return (bool) Setting::get('web_research_enabled', true)
            && filled(Setting::get('web_search_key', config('services.web_search.key')));
    }

    /**
     * Find other outlets covering this story and return their article text.
     * Excludes the post's own domain and any outlet already in $existingHosts.
     *
     * @param array<int,string> $existingHosts hostnames already used as sources
     * @return array<int,array{name:string,url:string,text:string}>
     */
    public function research(string $title, array $existingHosts = [], int $max = 3): array
    {
        if (! $this->enabled() || blank($title)) {
            return [];
        }

        $ownHost = $this->host((string) config('app.url'));
        $skip = array_map(fn ($h) => Str::lower($h), array_merge($existingHosts, [$ownHost]));

        $found = [];
        foreach ($this->searchResults($title, 10) as $r) {
            if (count($found) >= $max) {
                break;
            }
            $host = $this->host($r['url']);
            if ($host === '' || $this->blocked($host) || in_array($host, $skip, true)) {
                continue;
            }
            $skip[] = $host; // one article per outlet

            try {
                $text = $this->articles->extract($r['url'])['text'] ?? '';
            } catch (\Throwable) {
                continue;
            }
            if (blank($text) || str_word_count($text) < 150) {
                continue; // not a substantial article
            }

            $found[] = [
                'name' => $this->outletName($host),
                'url' => $r['url'],
                'text' => $text,
            ];
        }

        return $found;
    }

    /**
     * Query the configured search provider. Returns [{title,url}] web results.
     *
     * @return array<int,array{title:string,url:string}>
     */
    private function searchResults(string $query, int $count): array
    {
        $key = (string) Setting::get('web_search_key', config('services.web_search.key'));
        $provider = Str::lower((string) Setting::get('web_search_provider', config('services.web_search.provider', 'brave')));
        if (blank($key)) {
            return [];
        }

        try {
            if ($provider === 'google') {
                $cx = (string) Setting::get('web_search_cx', config('services.web_search.cx'));
                $res = Http::timeout(20)->acceptJson()->get('https://www.googleapis.com/customsearch/v1', [
                    'key' => $key, 'cx' => $cx, 'q' => $query, 'num' => min(10, $count),
                ])->throw()->json();

                return collect($res['items'] ?? [])
                    ->map(fn ($i) => ['title' => (string) ($i['title'] ?? ''), 'url' => (string) ($i['link'] ?? '')])
                    ->filter(fn ($i) => filled($i['url']))->values()->all();
            }

            // Default: Brave Search API.
            $res = Http::timeout(20)
                ->withHeaders(['X-Subscription-Token' => trim($key), 'Accept' => 'application/json'])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query, 'count' => min(20, $count),
                ])->throw()->json();

            return collect(data_get($res, 'web.results', []))
                ->map(fn ($i) => ['title' => (string) ($i['title'] ?? ''), 'url' => (string) ($i['url'] ?? '')])
                ->filter(fn ($i) => filled($i['url']))->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Web search failed (' . $provider . '): ' . $e->getMessage());

            return [];
        }
    }

    private function host(string $url): string
    {
        $h = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return Str::startsWith($h, 'www.') ? substr($h, 4) : $h;
    }

    private function blocked(string $host): bool
    {
        foreach (self::BLOCK as $b) {
            if ($host === $b || Str::endsWith($host, '.' . $b)) {
                return true;
            }
        }

        return false;
    }

    /** A readable outlet name from a hostname (e.g. apnews.com -> Apnews). */
    private function outletName(string $host): string
    {
        $base = explode('.', $host)[0] ?? $host;

        return Str::title(str_replace('-', ' ', $base));
    }
}
