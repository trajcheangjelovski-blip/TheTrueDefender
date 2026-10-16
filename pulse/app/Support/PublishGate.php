<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;

/**
 * Internal automated publishing checks, not Google approval requirements or a
 * substitute for source verification. Source/word thresholds are house settings.
 *
 * Thresholds are admin-tunable Settings so you can tighten them over time:
 *   min_sources_to_publish (default 3) — distinct sources required
 *   min_publish_words      (default 600) — body length floor
 *
 * Makes no external calls.
 */
class PublishGate
{
    private const OUTREACH = [
        'we reached out', 'we contacted',
        'told the true defender', 'in a statement to the true defender',
    ];

    /**
     * Reasons this post fails the publish bar. Empty array = it may publish.
     *
     * @return array<string,string> reason-key => human message
     */
    public static function failures(Post $post): array
    {
        $minSources = (int) Setting::get('min_sources_to_publish', 3);
        $minWords = (int) Setting::get('min_publish_words', 600);

        $fails = [];

        $words = str_word_count(strip_tags((string) $post->body));
        if ($words < $minWords) {
            $fails['thin'] = "Body is {$words} words; needs at least {$minWords}.";
        }

        $sources = self::distinctSourceCount($post);
        if ($sources < $minSources) {
            $fails['single-source'] = "Only {$sources} distinct source(s); needs at least {$minSources}.";
        }

        $plain = mb_strtolower(strip_tags((string) $post->body));
        if ($post->content_label === 'News' && preg_match('/<h[1-6][^>]*>\s*Our Take\s*<\/h[1-6]>/i', (string) $post->body)) {
            $fails['mixed-opinion'] = 'Move Our Take to a separate Opinion draft before publishing news.';
        }
        foreach (self::OUTREACH as $n) {
            if (str_contains($plain, $n)) {
                $fails['outreach'] = 'Body implies first-hand outreach this newsroom does not do.';
                break;
            }
        }
        if (ArticleSanitizer::hasPlaceholder($post->body) || ArticleSanitizer::hasPlaceholder($post->excerpt)) {
            $fails['placeholder'] = 'Leftover template/placeholder token in the text.';
        }

        return $fails;
    }

    public static function passes(Post $post): bool
    {
        return self::failures($post) === [];
    }

    /** Count recorded source hosts/names. This does not establish independent corroboration. */
    public static function distinctSourceCount(Post $post): int
    {
        $hosts = [];
        $names = [];

        if (is_array($post->sources)) {
            foreach ($post->sources as $s) {
                $url = is_array($s) ? ($s['url'] ?? null) : null;
                $name = is_array($s) ? ($s['publisher'] ?? $s['name'] ?? null) : (is_string($s) ? $s : null);
                if ($url && ($h = parse_url($url, PHP_URL_HOST))) {
                    $hosts[strtolower(preg_replace('/^www\./', '', $h))] = true;
                } elseif ($name) {
                    $names[mb_strtolower(trim($name))] = true;
                }
            }
        }

        if (! $hosts && ! $names && filled($post->source_url)) {
            if ($h = parse_url($post->source_url, PHP_URL_HOST)) {
                $hosts[strtolower(preg_replace('/^www\./', '', $h))] = true;
            } elseif (filled($post->source_name)) {
                $names[mb_strtolower(trim($post->source_name))] = true;
            }
        }

        return count($hosts) + count($names);
    }
}
