<?php

namespace App\Services;

use App\Models\IngestedItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Free complementary coverage from RSS entries we already ingested.
 * HEADLINE MATCHING IS ONLY DISCOVERY, NOT FACT VERIFICATION.
 * EditorialPipeline/FactChecker must validate event identity and claims.
 */
final class FeedRelatedResearcher
{
    private const STOPWORDS = [
        'about', 'after', 'again', 'amid', 'against', 'among', 'before',
        'could', 'during', 'from', 'have', 'into', 'more', 'over',
        'says', 'said', 'that', 'their', 'them', 'this', 'those',
        'under', 'will', 'with', 'would', 'news', 'latest', 'update',
    ];

    public function __construct(private ArticleFetcher $articles) {}

    /** Basic high-recall discovery score, never treated as proof of the same event. */
    public static function headlineMatch(string $target, string $candidate): bool
    {
        $a = self::tokens($target);
        $b = self::tokens($candidate);
        if (count($a) < 3 || count($b) < 3) {
            return false;
        }

        $overlap = count(array_intersect($a, $b));
        return $overlap >= 3 && $overlap / max(1, min(count($a), count($b))) >= 0.60;
    }

    private static function tokens(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($words, fn ($w) =>
            mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true)
        )));
    }

    public function research(string $headline, array $existingHosts = [], int $max = 3, ?Carbon $since = null): array
    {
        if (blank($headline) || $max <= 0) {
            return [];
        }

        $skip = array_map(fn ($h) => strtolower(preg_replace('/^www\./', '', $h)), $existingHosts);
        $found = [];

        // Database bounded to avoid a large full scan or excessive remote downloads.
        $candidates = IngestedItem::query()
            ->where('created_at', '>=', now()->subHours(48))
            ->whereNotNull('source_url')
            ->whereIn('status', ['processed', 'duplicate'])
            ->latest('created_at')
            ->limit(350)
            ->get(['source_url', 'title']);

        foreach ($candidates as $item) {
            if (count($found) >= $max || ! self::headlineMatch($headline, (string) $item->title)) {
                continue;
            }
            $url = (string) $item->source_url;
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host);
            if ($host === '' || ! in_array((string) parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
                || in_array($host, $skip, true)) {
                continue;
            }

            try {
                $page = $this->articles->extract($url);
                $published = filled($page['published_at'] ?? null) ? Carbon::parse($page['published_at']) : null;
                // A page updated today about an old event is NOT automatically new reporting.
                if (! $published || ($since && $published->lte($since))) {
                    continue;
                }
                $body = (string) ($page['text'] ?? '');
                if (str_word_count($body) < 150 || WebResearcher::isCopyOf((string) ($page['title'] ?? ''), $body, $found)) {
                    continue;
                }

                $found[] = [
                    'name' => $page['site_name'] ?: Str::headline(explode('.', $host)[0]),
                    'url' => $url,
                    'title' => $page['title'] ?: (string) $item->title,
                    'published_at' => $published->toIso8601String(),
                    'modified_at' => $page['modified_at'] ?? null,
                    'accessed_at' => now()->toIso8601String(),
                    'tier' => 2,
                    'text' => $body,
                ];
                $skip[] = $host;
            } catch (\Throwable) {
                continue;
            }
        }

        return $found;
    }
}
