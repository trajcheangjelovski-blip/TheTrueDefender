<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Link quality for news articles. A person's or organization's name is not a reason to link an encyclopedia:
 * the useful links are the original statement, the agency or government page, the original reporting and
 * other primary evidence. Wikipedia explains a term; it does not support a news claim.
 *
 * Wikipedia links are therefore removed (the text stays) unless the `link_wikipedia` setting is switched on.
 * Source links, internal links and links to official or primary pages are untouched.
 */
class LinkQuality
{
    public static function wikipediaAllowed(): bool
    {
        return (bool) (int) Setting::get('link_wikipedia', '0');
    }

    /** Does the HTML contain a Wikipedia link? */
    public static function hasWikipedia(string $html): bool
    {
        return (bool) preg_match('/<a\b[^>]*href=["\']https?:\/\/(?:[a-z0-9-]+\.)?wikipedia\.org\//i', $html);
    }

    /** Remove Wikipedia anchors, keeping their text. No-op when Wikipedia links are allowed. */
    public static function unwrapWikipedia(string $html): string
    {
        if (self::wikipediaAllowed() || ! self::hasWikipedia($html)) {
            return $html;
        }

        return (string) preg_replace('/<a\b[^>]*href=["\']https?:\/\/(?:[a-z0-9-]+\.)?wikipedia\.org\/[^"\']*["\'][^>]*>(.*?)<\/a>/is', '$1', $html);
    }
}
