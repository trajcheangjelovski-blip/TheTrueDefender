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
        // personal blogs / platforms — not news reporting
        'substack.com', 'medium.com', 'blogspot.com', 'wordpress.com', 'tumblr.com', 'quora.com',
        'scribd.com', 'newsbreak.com', 'ground.news', 'muckrack.com',
    ];

    /**
     * Source preference (editorial policy): wire services and official bodies
     * first, then established national and regional news organizations.
     * Ranking only — every source still has to support the claims it's used for.
     */
    private const TIER1 = ['apnews.com', 'reuters.com'];

    private const TIER2 = [
        'npr.org', 'pbs.org', 'bbc.com', 'bbc.co.uk', 'nytimes.com', 'washingtonpost.com', 'wsj.com',
        'usatoday.com', 'latimes.com', 'nbcnews.com', 'cbsnews.com', 'abcnews.go.com', 'cnn.com',
        'foxnews.com', 'politico.com', 'thehill.com', 'axios.com', 'bloomberg.com', 'nypost.com',
        'newsweek.com', 'time.com', 'chicagotribune.com', 'bostonglobe.com', 'oregonlive.com',
        'opb.org', 'sfchronicle.com', 'dallasnews.com', 'houstonchronicle.com', 'miamiherald.com',
        'seattletimes.com', 'nbclosangeles.com', 'ktla.com', 'kgw.com', 'koin.com', 'laist.com',
        'foxbusiness.com', 'cnbc.com', 'theguardian.com', 'aljazeera.com', 'scotusblog.com',
    ];

    /** Proper publisher names for well-known hosts (instead of "Latimes"). */
    private const PUBLISHERS = [
        'cpc.ncep.noaa.gov' => 'NOAA Climate Prediction Center', 'fire.ca.gov' => 'Cal Fire', 'ed.gov' => 'U.S. Department of Education',
        'mynewsla.com' => 'MyNewsLA', 'voiceofoc.org' => 'Voice of OC', 'eurekalert.org' => 'EurekAlert', 'highereddive.com' => 'Higher Ed Dive',
        'apnews.com' => 'Associated Press', 'reuters.com' => 'Reuters', 'npr.org' => 'NPR', 'pbs.org' => 'PBS',
        'bbc.com' => 'BBC News', 'bbc.co.uk' => 'BBC News', 'nytimes.com' => 'The New York Times',
        'washingtonpost.com' => 'The Washington Post', 'wsj.com' => 'The Wall Street Journal',
        'usatoday.com' => 'USA Today', 'latimes.com' => 'Los Angeles Times', 'nbcnews.com' => 'NBC News',
        'cbsnews.com' => 'CBS News', 'abcnews.go.com' => 'ABC News', 'cnn.com' => 'CNN', 'foxnews.com' => 'Fox News',
        'foxbusiness.com' => 'Fox Business', 'politico.com' => 'Politico', 'thehill.com' => 'The Hill',
        'axios.com' => 'Axios', 'bloomberg.com' => 'Bloomberg', 'nypost.com' => 'New York Post',
        'newsweek.com' => 'Newsweek', 'time.com' => 'Time', 'cnbc.com' => 'CNBC', 'theguardian.com' => 'The Guardian',
        'aljazeera.com' => 'Al Jazeera', 'oregonlive.com' => 'The Oregonian', 'opb.org' => 'Oregon Public Broadcasting',
        'nbclosangeles.com' => 'NBC Los Angeles', 'ktla.com' => 'KTLA', 'laist.com' => 'LAist', 'kgw.com' => 'KGW',
        'koin.com' => 'KOIN', 'sfchronicle.com' => 'San Francisco Chronicle', 'dallasnews.com' => 'The Dallas Morning News',
        'houstonchronicle.com' => 'Houston Chronicle', 'miamiherald.com' => 'Miami Herald',
        'seattletimes.com' => 'The Seattle Times', 'chicagotribune.com' => 'Chicago Tribune',
        'bostonglobe.com' => 'The Boston Globe', 'scotusblog.com' => 'SCOTUSblog',
    ];

    public function __construct(private ArticleFetcher $articles) {}

    /** 0 = wire/official, 1 = established outlet, 2 = other. */
    public function tier(string $host): int
    {
        $host = Str::lower($host);
        if ($this->matches($host, self::TIER1) || Str::endsWith($host, ['.gov', '.mil']) || preg_match('/(^|\.)(courts?|police|sheriff)\./', $host)) {
            return 0;
        }

        return $this->matches($host, self::TIER2) ? 1 : 2;
    }

    /** A publisher's proper name: known list, then the page's og:site_name, then the search profile. */
    public function publisherName(string $host, ?string $siteName = null, ?string $profileName = null): string
    {
        foreach (self::PUBLISHERS as $h => $name) {
            if ($host === $h || Str::endsWith($host, '.' . $h)) {
                return $name;
            }
        }
        foreach ([$siteName, $profileName] as $candidate) {
            $candidate = trim((string) $candidate);
            $looksLikeUrl = Str::contains($candidate, ['://', 'www.']) || preg_match('/\.[a-z]{2,6}$/i', $candidate);
            if ($candidate !== '' && ! $looksLikeUrl && mb_strlen($candidate) <= 60 && ! in_array(Str::lower($candidate), ['english', 'news', 'home', 'homepage'], true)) {
                return $candidate;
            }
        }

        return $this->outletName($host);
    }

    /**
     * Internal source record (no article text) for editorial review and fact-checking.
     */
    public function sourceRecord(string $url, array $page = [], ?string $profileName = null): array
    {
        $host = $this->host($url);

        return [
            'name' => $this->publisherName($host, $page['site_name'] ?? null, $profileName),
            'url' => $url,
            'title' => $page['title'] ?? null,
            'published_at' => $page['published_at'] ?? null,
            'modified_at' => $page['modified_at'] ?? null,
            'accessed_at' => now()->toIso8601String(),
            'tier' => $this->tier($host),
        ];
    }

    /**
     * Is this page a republished copy of a source we already have? Same headline
     * (ignoring a " - Site Name" suffix and punctuation), or most of its opening
     * text identical.
     *
     * @param array<int,array{title?:?string,text?:string}> $existing
     */
    public static function isCopyOf(string $title, string $text, array $existing): bool
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($s))));
        $headline = fn (string $t) => $norm(preg_replace('/\s+[|\-–—]\s+[^|\-–—]{2,40}$/u', '', $t));
        $opening = fn (string $t) => mb_substr($norm($t), 0, 400);

        $h = $headline($title);
        $o = $opening($text);
        foreach ($existing as $e) {
            if ($h !== '' && $h === $headline((string) ($e['title'] ?? ''))) {
                return true;
            }
            if (mb_strlen($o) >= 200 && $o === $opening((string) ($e['text'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $host, array $list): bool
    {
        foreach ($list as $h) {
            if ($host === $h || Str::endsWith($host, '.' . $h)) {
                return true;
            }
        }

        return false;
    }

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
     * Results are ranked by source preference (wire/official, then established
     * outlets, then search order) and each comes with its storable record:
     * publisher, headline, publication date and access time.
     *
     * @param array<int,string> $existingHosts hostnames already used as sources
     * @return array<int,array{name:string,url:string,text:string,title:?string,published_at:?string,accessed_at:string,tier:int}>
     */
    public function research(string $title, array $existingHosts = [], int $max = 3): array
    {
        if (! $this->enabled() || blank($title)) {
            return [];
        }

        $ownHost = $this->host((string) config('app.url'));
        $skip = array_map(fn ($h) => Str::lower($h), array_merge($existingHosts, [$ownHost]));

        $results = collect($this->searchResults($title, 12))
            ->map(fn ($r, $i) => $r + ['host' => $this->host($r['url']), 'rank' => $i])
            ->filter(fn ($r) => $r['host'] !== '' && ! $this->blocked($r['host']) && ! in_array($r['host'], $skip, true))
            ->sortBy(fn ($r) => $this->tier($r['host']) * 100 + $r['rank'])   // preferred sources first
            ->values();

        $found = [];
        foreach ($results as $r) {
            if (count($found) >= $max) {
                break;
            }
            if (in_array($r['host'], $skip, true)) {
                continue;
            }
            $skip[] = $r['host']; // one article per outlet

            try {
                $page = $this->articles->extract($r['url']);
            } catch (\Throwable) {
                continue;
            }
            $text = (string) ($page['text'] ?? '');
            if (blank($text) || str_word_count($text) < 150) {
                continue; // not a substantial article
            }
            // Scraper sites republish the same article under its original headline;
            // a copy is not an independent source and must not inflate the count.
            if (self::isCopyOf((string) ($page['title'] ?? $r['title']), $text, $found)) {
                continue;
            }

            $found[] = ['text' => $text] + $this->sourceRecord($r['url'], $page, $r['profile'] ?? null);
        }

        return $found;
    }

    /**
     * Query the configured search provider. Returns [{title,url,profile}] web
     * results. Cached per query for 6 hours, so retries and the fact-check stage
     * never pay for the same search twice.
     *
     * @return array<int,array{title:string,url:string,profile:?string}>
     */
    private function searchResults(string $query, int $count): array
    {
        $key = (string) Setting::get('web_search_key', config('services.web_search.key'));
        $provider = Str::lower((string) Setting::get('web_search_provider', config('services.web_search.provider', 'brave')));
        if (blank($key)) {
            return [];
        }

        $cacheKey = 'web-search:' . md5($provider . '|' . $count . '|' . Str::lower(trim($query)));
        if (is_array($cached = \Illuminate\Support\Facades\Cache::get($cacheKey))) {
            return $cached;
        }
        $results = $this->querySearch($provider, $key, $query, $count);
        if ($results) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    private function querySearch(string $provider, string $key, string $query, int $count): array
    {
        try {
            if ($provider === 'google') {
                $cx = (string) Setting::get('web_search_cx', config('services.web_search.cx'));
                $res = Http::timeout(20)->acceptJson()->get('https://www.googleapis.com/customsearch/v1', [
                    'key' => $key, 'cx' => $cx, 'q' => $query, 'num' => min(10, $count),
                ])->throw()->json();

                return collect($res['items'] ?? [])
                    ->map(fn ($i) => ['title' => (string) ($i['title'] ?? ''), 'url' => (string) ($i['link'] ?? ''), 'profile' => null])
                    ->filter(fn ($i) => filled($i['url']))->values()->all();
            }

            // Default: Brave Search API.
            $res = Http::timeout(20)
                ->withHeaders(['X-Subscription-Token' => trim($key), 'Accept' => 'application/json'])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query, 'count' => min(20, $count),
                ])->throw()->json();

            return collect(data_get($res, 'web.results', []))
                ->map(fn ($i) => [
                    'title' => (string) ($i['title'] ?? ''),
                    'url' => (string) ($i['url'] ?? ''),
                    'profile' => data_get($i, 'profile.name'),
                ])
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

    public function blockedHost(string $host): bool
    {
        return $this->blocked(Str::lower($host));
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
