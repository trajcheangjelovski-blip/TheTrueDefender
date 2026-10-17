<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;

/**
 * The new content model's publish bar, in one place. A post may only go live if
 * it clears every rule here — this is what makes "multi-source, substantial,
 * genuinely-added-value" structural rather than aspirational, and what keeps the
 * site out of AdSense "low value content" territory going forward.
 *
 * Thresholds are admin-tunable Settings so you can tighten them over time:
 *   min_sources_to_publish (default 3) — distinct sources required
 *   min_publish_words      (default 600) — body length floor
 *
 * Makes no external calls.
 */
class PublishGate
{
    private const AI_TELLS = [
        'the new report says', 'the new report adds', 'the report says', 'the report adds',
        'the report also says', 'according to the report', 'the article says', 'the source article',
    ];

    private const OUTREACH = [
        'did not respond to a request for comment', 'did not respond to requests for comment',
        'did not immediately respond to a request for comment', 'we reached out', 'we contacted',
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
        foreach (self::AI_TELLS as $n) {
            if (str_contains($plain, $n)) {
                $fails['ai-tell'] = 'Body contains a single-source "the report says" tell.';
                break;
            }
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

        // ── Originality: no headline echo, no repetition, no stock template ──
        $sentences = self::sentences((string) $post->body);
        if ($sentences && self::echoesHeadline((string) $post->title, $sentences[0])) {
            $fails['headline-echo'] = 'First sentence restates the headline.';
        }
        $long = array_filter($sentences, fn ($s) => str_word_count($s) >= 8);
        if (count($long) !== count(array_unique($long))) {
            $fails['repeated-sentence'] = 'A sentence is repeated within the article.';
        }
        if ($long && self::reusesRecentSentence($post, $long)) {
            $fails['reused-sentence'] = 'Reuses a sentence from a recent article.';
        }
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', (string) $post->body, $h);
        foreach ($h[1] ?? [] as $heading) {
            if (in_array(self::norm(strip_tags($heading)), self::GENERIC_HEADINGS, true)) {
                $fails['generic-heading'] = 'Uses a stock template heading ("' . trim(strip_tags($heading)) . '").';
                break;
            }
        }

        return $fails;
    }

    /** Stock section labels that make every article look the same. */
    private const GENERIC_HEADINGS = [
        'background context', 'background and context', 'background', 'context', 'what happens next',
        'whats next', 'what comes next', 'why it matters', 'why this matters', 'key details', 'the details',
        'the bottom line', 'bottom line', 'looking ahead', 'conclusion', 'final thoughts', 'the big picture',
    ];

    /** Lowercased words only — for comparing text regardless of punctuation. */
    private static function norm(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_strtolower(html_entity_decode($text)))));
    }

    /** Normalized sentences of an HTML body, excluding headings. */
    private static function sentences(string $html): array
    {
        $plain = strip_tags(preg_replace('/<h2[^>]*>.*?<\/h2>/is', ' ', str_replace('</p>', ' </p>', $html)));
        $parts = preg_split('/(?<=[.!?])\s+/', html_entity_decode($plain)) ?: [];

        return array_values(array_filter(array_map([self::class, 'norm'], $parts)));
    }

    /** Does the opening sentence start by repeating the headline (first 5+ words)? */
    private static function echoesHeadline(string $title, string $first): bool
    {
        $t = explode(' ', self::norm($title));
        $n = min(5, count($t));
        if ($n < 3) {
            return false;
        }

        return implode(' ', array_slice(explode(' ', $first), 0, $n)) === implode(' ', array_slice($t, 0, $n));
    }

    /** Is any long sentence identical to one in our 30 most recent published articles? */
    private static function reusesRecentSentence(Post $post, array $long): bool
    {
        $mine = array_flip($long);
        foreach (Post::where('status', 'published')->where('id', '!=', $post->id ?? 0)
            ->latest('published_at')->take(30)->pluck('body') as $body) {
            foreach (self::sentences((string) $body) as $s) {
                if (isset($mine[$s])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function passes(Post $post): bool
    {
        return self::failures($post) === [];
    }

    /** Count distinct sources, by host where URLs are present. */
    public static function distinctSourceCount(Post $post): int
    {
        $hosts = [];
        $names = [];

        if (is_array($post->sources)) {
            foreach ($post->sources as $s) {
                $url = is_array($s) ? ($s['url'] ?? null) : null;
                $name = is_array($s) ? ($s['name'] ?? null) : (is_string($s) ? $s : null);
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
