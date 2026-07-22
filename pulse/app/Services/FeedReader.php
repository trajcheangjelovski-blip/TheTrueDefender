<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FeedReader
{
    /**
     * Fetch and parse an RSS 2.0 or Atom feed.
     *
     * @return array<int,array{guid:string,title:string,link:string,summary:string}>
     */
    public function read(string $url, int $limit = 10): array
    {
        $body = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'TheDailyPulse/1.0 (+news aggregator)'])
            ->get($url)
            ->throw()
            ->body();

        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) {
            return [];
        }

        $items = [];

        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $link = (string) $item->link;
                $guid = (string) ($item->guid ?? '') ?: $link;
                $description = (string) $item->description;
                $dc = $item->children('http://purl.org/dc/elements/1.1/');
                $items[] = [
                    'guid' => $guid,
                    'title' => trim((string) $item->title),
                    'link' => $link,
                    'summary' => $this->clean($description),
                    'image' => $this->extractImage($item, $description),
                    'published_at' => $this->parseDate((string) ($item->pubDate ?? $dc->date ?? '')),
                ];
                if (count($items) >= $limit) break;
            }
            return $items;
        }

        // Atom
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    if ((string) $l['rel'] === '' || (string) $l['rel'] === 'alternate') {
                        $link = (string) $l['href'];
                        break;
                    }
                }
                $guid = (string) ($entry->id ?? '') ?: $link;
                $summary = (string) ($entry->summary ?? $entry->content ?? '');
                $items[] = [
                    'guid' => $guid,
                    'title' => trim((string) $entry->title),
                    'link' => $link,
                    'summary' => $this->clean($summary),
                    'image' => $this->extractImage($entry, $summary),
                    'published_at' => $this->parseDate((string) ($entry->published ?? $entry->updated ?? '')),
                ];
                if (count($items) >= $limit) break;
            }
        }

        return $items;
    }

    /** Pull an image URL from Media RSS tags, enclosures, or inline <img>. */
    private function extractImage(\SimpleXMLElement $node, string $html): ?string
    {
        // Media RSS: <media:content url> / <media:thumbnail url>
        $media = $node->children('http://search.yahoo.com/mrss/');
        foreach (['content', 'thumbnail'] as $tag) {
            if (isset($media->$tag)) {
                foreach ($media->$tag as $m) {
                    // Plain (non-namespaced) attributes require ->attributes().
                    $url = (string) ($m->attributes()->url ?? '');
                    if ($url !== '') return $url;
                }
            }
        }

        // <enclosure url type="image/...">
        if (isset($node->enclosure)) {
            foreach ($node->enclosure as $enc) {
                if (str_starts_with((string) ($enc['type'] ?? ''), 'image')) {
                    return (string) $enc['url'];
                }
            }
        }

        // First <img src> inside the description/content HTML
        if ($html !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $mm)) {
            return $mm[1];
        }

        return null;
    }

    private function clean(string $html): string
    {
        return Str::of(strip_tags($html))->squish()->limit(600)->value();
    }

    /** Parse a feed date (RFC822 / ISO8601) to a Carbon instance, or null. */
    private function parseDate(string $raw): ?\Illuminate\Support\Carbon
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
