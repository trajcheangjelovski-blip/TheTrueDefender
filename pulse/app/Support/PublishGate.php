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
    public static function failures(Post $post, ?int $minSourcesOverride = null): array
    {
        $minSources = $minSourcesOverride ?? (int) Setting::get('min_sources_to_publish', 3);
        $minWords = self::minWords($post->story_type, (bool) $post->is_breaking);

        $fails = [];

        // Independent fact-check (EditorialPipeline / FactChecker): a news story
        // goes live only when its verdict is "ready". Opinion columns are checked
        // by opinions:generate before they are created.
        if (! self::isOpinion($post) && $post->review_status !== 'ready') {
            $fails['not-verified'] = 'Not verified by the independent fact-check (status: ' . ($post->review_status ?: 'not checked') . ').';
        }
        if (! empty($post->fact_check['unverified'])) {
            $fails['unverified-claims'] = 'Unverified factual claims require editorial research before publication.';
        }

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
        if ($stale = self::staleness($post)) {
            $fails['stale'] = $stale;
        }

        return $fails + self::originality($post);
    }

    /**
     * The word floor for a story type — one rule for ingest and publishing.
     * Editorial policy: length follows the story — a tight breaking report is
     * better than a padded one, so short story types get a lower floor than
     * min_publish_words. A story flagged breaking gets the breaking floor
     * whatever structure the writer labeled it ("crime", "political", …).
     */
    public static function minWords(?string $storyType, bool $breaking = false): int
    {
        $min = (int) Setting::get('min_publish_words', 600);

        return match (true) {
            $breaking, $storyType === 'breaking' => min($min, 350),
            in_array($storyType, ['crime', 'human_interest'], true) => min($min, 400),
            default => $min,
        };
    }

    /**
     * The structural rules for a story published straight from ingest (it has
     * no research packet or fact-check yet): no switched-off section, no opinion
     * section in news, no placeholder, not stale. Empty array = may publish.
     *
     * @return array<string,string>
     */
    public static function ingestFailures(Post $post): array
    {
        $fails = [];
        if (! ArticleSections::sourcesEnabled() && PublicSourceSections::hasSection($post->body)) {
            $fails['public-source-section'] = PublicSourceSections::MESSAGE;
        }
        if ($found = ArticleSections::found($post->body, self::isOpinion($post))) {
            $fails['disabled-section'] = ArticleSections::message($found);
        }
        if (! self::isOpinion($post) && ($label = self::opinionSection((string) $post->body))) {
            $fails['opinion-in-news'] = 'News article contains an opinion section ("' . $label . '").';
        }
        if (ArticleSanitizer::hasPlaceholder($post->body) || ArticleSanitizer::hasPlaceholder($post->excerpt)) {
            $fails['placeholder'] = 'Leftover template/placeholder token in the text.';
        }
        if ($stale = self::staleness($post)) {
            $fails['stale'] = $stale;
        }

        return $fails;
    }

    /**
     * Too old to publish? Measured from when the ORIGINAL outlet published
     * (posts.source_published_at), against max_publish_age_hours (default 6).
     * Posts without a source date (manual, opinion) are not judged here.
     */
    public static function staleness(Post $post): ?string
    {
        $max = (float) Setting::get('max_publish_age_hours', 6);
        // Story of Hope (human-interest) stays current for days, not hours.
        if (\App\Services\ResearchAnalyst::isHumanInterest(\App\Models\Category::whereKey($post->category_id)->value('slug'))) {
            $max = \App\Services\ResearchAnalyst::HUMAN_INTEREST_DAYS * 24;
        }
        $published = $post->source_published_at;
        if ($max <= 0 || ! $published) {
            return null;
        }
        $hours = $published->diffInMinutes(now()) / 60;

        return $hours > $max
            ? sprintf('Stale: the source published this %.1fh ago (limit %sh).', $hours, rtrim(rtrim(number_format($max, 1), '0'), '.'))
            : null;
    }

    /** An opinion-section heading (any level, or a bold label) in a body, else null. */
    private static function opinionSection(string $body): ?string
    {
        if (preg_match('~<h[1-6][^>]*>\s*(Our Take|Analysis|Opinion|Editorial)\s*:?\s*</h[1-6]>~i', $body, $m)
            || preg_match('~<(?:p|div)[^>]*>\s*<(?:strong|b)>\s*(Our Take|Analysis|Opinion|Editorial)\s*:?\s*</(?:strong|b)>~i', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Originality checks on their own (no source/length rules) — used by failures()
     * and by single-author pieces like opinion columns. Empty array = original.
     *
     * @return array<string,string>
     */
    public static function originality(Post $post): array
    {
        $fails = [];
        if (! ArticleSections::sourcesEnabled() && PublicSourceSections::hasSection($post->body)) {
            $fails['public-source-section'] = PublicSourceSections::MESSAGE;
        }
        if ($found = ArticleSections::found($post->body, self::isOpinion($post))) {
            $fails['disabled-section'] = ArticleSections::message($found);
        }
        $sentences = self::sentences((string) $post->body);
        if ($sentences && self::echoesHeadline((string) $post->title, $sentences[0])) {
            $fails['headline-echo'] = 'First sentence restates the headline.';
        }
        if (LeadCheck::excerptRepeatsLead($post->excerpt, (string) $post->body)) {
            $fails['excerpt-repeats-lead'] = 'The excerpt and the opening paragraph repeat nearly the same sentences. Keep the central fact in both if needed, '
                . 'but open the article with a different verified detail or the next development instead of restating the excerpt.';
        }
        if (! self::isOpinion($post) && ($ending = LeadCheck::backgroundEnding((string) $post->body))) {
            $fails['background-ending'] = 'The article ends on incidental background ("' . mb_substr($ending, 0, 90) . '…"). End on the latest verified '
                . 'development, a verified follow-up or next step, or another relevant verified detail from the research instead.';
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
        // Stock phrases are judged on OUR prose only: text inside quotation marks is
        // something a person said (reporting), not filler the writer added.
        $unquotedHtml = preg_replace('/[“"][^”"]*[”"]/u', ' ', html_entity_decode((string) $post->body, ENT_QUOTES | ENT_HTML5));
        $flat = ' ' . implode(' ', self::sentences($unquotedHtml)) . ' ';
        foreach (self::STOCK_PHRASES as $phrase) {
            if (str_contains($flat, ' ' . self::norm($phrase) . ' ')) {
                $fails['stock-phrase'] = 'Uses the stock phrase "' . $phrase . '".';
                break;
            }
        }
        if (self::takeReusesPhrase($post)) {
            $fails['take-overlap'] = '"Our Take" repeats an 8-word phrase from a recent article\'s "Our Take".';
        }

        // News is not opinion (policy §7): no opinion section inside a news article.
        if (! self::isOpinion($post) && ($label = self::opinionSection((string) $post->body))) {
            $fails['opinion-in-news'] = 'News article contains an opinion section ("' . $label . '").';
        }

        // No rhetorical questions (policy §4): questions outside quotations.
        $plain = html_entity_decode(strip_tags(preg_replace('/<h2[^>]*>.*?<\/h2>/is', ' ', (string) $post->body)));
        $unquoted = preg_replace('/[“"][^”"]*[”"]/u', ' ', $plain);
        if (substr_count($unquoted, '?') >= 2) {
            $fails['rhetorical-question'] = 'Uses rhetorical questions outside quotations.';
        }

        return $fails;
    }

    /**
     * Formulaic phrases the writer had converged on (and the AI's usual fillers).
     * The writer prompts are told to avoid exactly this list; this enforces it.
     */
    public const STOCK_PHRASES = [
        'the lesson is', 'the lesson here', 'is a reminder', 'a reminder that', 'serves as a reminder',
        'the takeaway', 'the bottom line', 'why this matters', 'why it matters', 'what stands out',
        'make no mistake', 'remains to be seen', 'only time will tell', 'at the end of the day',
        'not theater', 'instead of theater', 'the real issue is', 'for readers', 'americans should',
        'readers should', 'it is worth noting', "it's worth noting", 'one thing is clear',
        'now more than ever', 'speaks volumes', 'should pay attention', 'worth defending',
        'in todays fast paced world', 'distant promises',
        // Editorial policy §5 — generic AI filler
        'in a shocking turn of events', 'sparked widespread debate', 'raises serious questions',
        'as the situation continues to unfold', 'stark reminder', 'sent shockwaves',
        'in an increasingly divided nation', 'underscores the importance of',
        'reignited a national conversation', 'highlights growing concerns', 'the coming days will be critical',
        // Vague attribution (policy §3) — name the outlet or official instead
        'the sources said', 'the sources also said', 'the sources describe', 'sources say', 'sources said',
        'reports indicate', 'reports said', 'reports identified', 'according to reports',
    ];

    /** Is this an Opinion-section piece (single-author argument, not news)? */
    public static function isOpinion(Post $post): bool
    {
        static $opinionId = null;
        $opinionId ??= (int) \App\Models\Category::where('slug', 'opinion')->value('id');

        return $opinionId && (int) $post->category_id === $opinionId;
    }

    /** Does this article's "Our Take" share an 8-word run with a recent "Our Take"? */
    private static function takeReusesPhrase(Post $post): bool
    {
        $mine = self::shingles(\App\Services\Rewriter::takeText((string) $post->body), 8);
        if (! $mine) {
            return false;
        }
        foreach (Post::where('status', 'published')->where('id', '!=', $post->id ?? 0)
            ->latest('published_at')->take(30)->pluck('body') as $body) {
            if (array_intersect_key($mine, self::shingles(\App\Services\Rewriter::takeText((string) $body), 8))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,true> every run of $n consecutive normalized words */
    private static function shingles(string $text, int $n): array
    {
        $w = array_values(array_filter(explode(' ', self::norm($text))));
        $out = [];
        for ($i = 0; $i + $n <= count($w); $i++) {
            $out[implode(' ', array_slice($w, $i, $n))] = true;
        }

        return $out;
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

    /**
     * Normalized sentences of an HTML body, excluding headings and the automatic
     * "Related: <title>" link line (SeoOptimizer adds it; two articles linking the
     * same story would otherwise count as a reused sentence).
     */
    private static function sentences(string $html): array
    {
        $html = preg_replace('/<p[^>]*class="[^"]*related-inline[^"]*"[^>]*>.*?<\/p>/is', ' ', $html);
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
        // Independent pieces of reporting, not websites: copies, syndication and aggregators share a group
        // (see SourceRelations). Older records without relationship data fall back to counting hosts.
        if (is_array($post->sources)) {
            $groups = [];
            foreach ($post->sources as $s) {
                if (is_array($s) && isset($s['group']) && ($s['kind'] ?? 'news') !== 'background_only') {
                    $groups[(int) $s['group']] = true;
                }
            }
            if ($groups) {
                return count($groups);
            }
        }
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
