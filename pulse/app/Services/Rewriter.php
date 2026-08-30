<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Rewriter
{
    /**
     * Rewrite a source news item into an original summary in the site's voice.
     * Returns ['title' => ..., 'excerpt' => ..., 'body' => '<p>...</p>'].
     *
     * Uses OpenAI when a key is configured; otherwise falls back to a safe
     * non-AI stub so the pipeline is fully testable before the live rewrite.
     *
     * @param array{title:string,summary:string,link:string} $item
     */
    public function rewrite(array $item, string $categoryName, string $sourceName, array $categories = []): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));

        if (blank($key)) {
            return $this->stub($item);
        }

        try {
            return $this->viaOpenAI($item, $categoryName, $sourceName, $key, $categories);
        } catch (\Throwable $e) {
            Log::warning('AI rewrite failed, using stub: ' . $e->getMessage());
            return $this->stub($item);
        }
    }

    /**
     * Classify an existing article into the best-fit category slug (no rewrite).
     *
     * @param array<int,array{slug:string,name:string}> $categories
     */
    public function classifyCategory(string $title, string $body, array $categories): ?string
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        $slugs = array_column($categories, 'slug');
        if (blank($key) || empty($slugs)) {
            return null;
        }

        $hints = [
            'politics' => 'US politics/government, elections, Congress, White House, policy, political figures',
            'us-news' => 'US domestic non-political — crime, weather/disasters, business, health, society',
            'world' => 'International news mainly outside the US',
            'story-of-hope' => 'Uplifting, positive, inspiring human-interest stories',
        ];
        $lines = collect($categories)->map(fn ($c) => "- {$c['slug']}: " . ($hints[$c['slug']] ?? $c['name']))->implode("\n");

        try {
            set_time_limit(60);
            $response = Http::withToken(trim($key))->timeout(30)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => "Classify the article into EXACTLY ONE category slug:\n{$lines}\nWhen both political and US-domestic, prefer politics."],
                        ['role' => 'user', 'content' => 'TITLE: ' . $title . "\n\n" . Str::limit(strip_tags($body), 1500, '')],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'category',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['category' => ['type' => 'string', 'enum' => array_values($slugs)]],
                                'required' => ['category'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            return json_decode(data_get($response->json(), 'choices.0.message.content', ''), true)['category'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Category classify failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Cheap editorial PRE-SCREEN: score a story 0-100 for how strongly it deserves
     * to run on this outlet's feed, from just the headline + summary — BEFORE the
     * expensive full-article fetch, rewrite, and AI image. Weak/off-brand items are
     * then skipped for almost no cost. FAILS OPEN (returns 100) on any error, so an
     * API blip can never silently starve the whole feed.
     *
     * @return array{score:int, reason:string}
     */
    public function score(string $title, string $summary): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return ['score' => 100, 'urgent' => false, 'reason' => 'scoring disabled (no key)'];
        }

        $site = config('app.name', 'TheTrueDefender');
        $guide = Setting::get('editorial_score_guide'); // optional admin override/extra rubric

        $system = <<<SYS
        You are the managing editor of "{$site}", a US news outlet whose readers follow
        American politics, Donald Trump, elections, Congress, immigration, US national news,
        and uplifting American human-interest stories.

        Rate how strongly THIS story deserves to run, 0-100, using this TOPIC PRIORITY
        (this outlet is political first):
        - 85-100: MAJOR US POLITICS — Trump, elections, Congress, the White House, DOJ/
          courts, immigration, major federal policy or legal rulings. This is the TOP
          priority; strong political stories belong here.
        - 78-95: MAJOR DISASTERS & breaking public-safety news — natural disasters,
          mass-casualty events, major attacks or accidents (especially with a US angle).
          Second priority, and usually time-critical.
        - 60-77: other solid, relevant US national news your readers would click.
        - 40-59: marginal — routine/procedural updates, minor or soft news.
        - 0-39: OFF-BRAND or low value — foreign local news with no US angle, celebrity
          gossip, sports minutiae, thin/no-substance blurbs, or trivia.
        Also set "urgent": true ONLY for a genuinely breaking, time-sensitive MAJOR story
        (top-priority US politics or a major disaster) that must publish IMMEDIATELY.
        Everything that can reasonably wait to be compared against later stories is urgent=false.
        Be discerning and honest; most routine wire items land 45-65, and urgent is rare. Do not inflate.
        Give a one-sentence reason (max 20 words).
        SYS;
        if (filled($guide)) {
            $system .= "\n\nExtra editorial guidance:\n" . $guide;
        }

        try {
            set_time_limit(30);
            $response = Http::withToken(trim($key))->timeout(25)
                ->retry(1, 800, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "HEADLINE: {$title}\n\nSUMMARY: " . Str::limit(strip_tags($summary), 800, '')],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'editorial_score',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'score' => ['type' => 'integer'],
                                    'urgent' => ['type' => 'boolean'],
                                    'reason' => ['type' => 'string'],
                                ],
                                'required' => ['score', 'urgent', 'reason'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);
            $score = (int) max(0, min(100, (int) ($data['score'] ?? 100)));

            return ['score' => $score, 'urgent' => (bool) ($data['urgent'] ?? false), 'reason' => Str::limit((string) ($data['reason'] ?? ''), 200, '')];
        } catch (\Throwable $e) {
            Log::warning('Editorial score failed (failing open): ' . $e->getMessage());

            return ['score' => 100, 'urgent' => false, 'reason' => 'scoring error — passed through'];
        }
    }

    /** @param array<int,array{slug:string,name:string}> $categories */
    private function viaOpenAI(array $item, string $categoryName, string $sourceName, string $key, array $categories = []): array
    {
        // AI calls can outlive the web server's 30s limit — extend per call.
        set_time_limit(180);

        $site = config('app.name', 'TheTrueDefender');
        $custom = Setting::get('ai_instructions'); // your custom editorial guidance, taught in the admin
        $fullText = $item['full_text'] ?? null;    // full source article, when the fetch succeeded

        // Category classification guidance (falls back to a fixed list if none passed).
        $catHints = [
            'politics' => 'US politics/government — elections, Congress, the White House, policy, political figures, campaigns',
            'us-news' => 'US domestic news that is NOT primarily political — crime, weather/disasters, business, health, society, local US events',
            'world' => 'International news happening mainly outside the United States',
            'story-of-hope' => 'Uplifting, positive, inspiring human-interest stories (rescues, generosity, comebacks, community)',
        ];
        $slugs = $categories ? array_column($categories, 'slug') : array_keys($catHints);
        $catLines = collect($categories ?: array_map(fn ($s) => ['slug' => $s, 'name' => ucwords(str_replace('-', ' ', $s))], $slugs))
            ->map(fn ($c) => '  - ' . $c['slug'] . ' (' . $c['name'] . '): ' . ($catHints[$c['slug']] ?? $c['name']))
            ->implode("\n");

        // With the full article we can write a substantial, original story with
        // added value; with only the RSS snippet we stay short (and the pipeline
        // holds those as drafts so thin pieces never publish).
        $lengthRule = filled($fullText)
            ? 'Body: a SUBSTANTIAL, original news article — this is a HARD requirement of AT LEAST 450 words, '
              . 'ideally 550-800 — in 6-9 paragraphs wrapped in <p></p> HTML tags. NEVER file a short summary. '
              . 'You MUST include ALL of the following, each as its own paragraph(s): (1) a strong lead; '
              . '(2) the key facts, figures and any reactions/quotes from the source; (3) a "background & context" '
              . 'section explaining how we got here and the wider situation; (4) a closing on why it matters to '
              . 'American readers and what to watch next. Reach the length by EXPANDING with genuine context, '
              . 'background and significance drawn from the source material and widely-known general context — never '
              . 'with filler or repetition. Ground every SPECIFIC claim (quotes, numbers, dates, names) in the source; '
              . 'do not invent them. If the source is brief, still develop the background and significance to length.'
            : 'Body: 2-3 short paragraphs, ~120-220 words total, wrapped in <p></p> HTML tags.';

        $system = <<<SYS
        You are a news editor for "{$site}", an independent US news outlet.
        Rewrite the source material below into an ORIGINAL news article in your own words.
        Rules:
        - Do NOT copy sentences or distinctive phrasing from the source; write fresh, neutral, factual prose.
        - Only use facts present in the provided source material. Do not invent quotes, numbers, or details.
        - Write ONLY the article itself. NEVER include meta-commentary, SEO notes, or process text —
          no "the focus keyword is…", "the phrase people will search for…", "in this article we will…",
          "meta description", "according to the provided information", "as an AI", or similar. Write like a
          human journalist filing a finished story, not instructions to a tool.
        - CRITICAL: write as if YOU reported this story directly. NEVER reveal or refer to your inputs
          or process. Do NOT mention "the summary", "the snippet", "the provided text/article/information",
          "the source material", or "the report provided". NEVER write that details "were not provided",
          "are not specified", "were not mentioned", or that information is missing/limited — if a detail
          isn't known, simply omit it. Do not hedge in ways that expose that you worked from a short blurb.
        - If the source material is brief, write a shorter but COMPLETE, self-contained story. Never pad it
          with sentences about what the source did or didn't say.
        - {$lengthRule}
        - Write a fresh, punchy headline (not identical to the source) and a one-sentence excerpt.
        - Also write social_text: a single punchy social-media caption (max 180 characters) that
          hooks readers to click through. No hashtags, no URL, no quotation marks; at most one emoji.
        - Source: {$sourceName} (attribution is added separately).

        Classify the story into EXACTLY ONE category by its actual content (ignore which
        feed it came from). Return its slug in "category":
        {$catLines}
        Choose the single best fit; when a story is both political and US-domestic, prefer politics.

        Also return "tags": 3 to 5 evergreen TOPIC labels for this story — the durable
        subjects a reader would follow over time, NOT one-off specifics. Use broad, reusable
        entities: people, places, organizations, and ongoing issues (e.g. "Immigration",
        "Donald Trump", "Federal Reserve", "Ukraine War", "Supreme Court"). Title Case,
        1-3 words each, no hashtags. Prefer labels that many future stories could also share.

        Also return "takeaways": 3 to 4 short bullet points capturing the single most
        important facts of the story ("the bottom line"). One sentence each, ≤22 words,
        no leading dashes, factual and self-contained. Grounded ONLY in the article — never
        invent. These render as a summary box, so they must stand alone without the article.

        Also return "faqs": 3 to 4 genuine questions a reader would ask about THIS story,
        each with a concise 1-3 sentence answer grounded ONLY in the article's facts.
        Write real, useful questions (who/what/when/why/how/what next) — never filler like
        "What is this article about?". If a question can't be answered from the facts, omit it.

        Also classify the story's prominence — be CONSERVATIVE, most stories are neither:
        - is_breaking: TRUE only for urgent, just-happened major events (mass-casualty
          events, death/attack on a major figure, war escalation, major disaster, a
          market/political shock). Routine updates, analysis, and features are FALSE.
        - is_top_story: TRUE only for nationally significant stories worth featuring on
          the front page. Ordinary daily news is FALSE.
        - is_trending: TRUE only for stories likely to draw wide public interest, sharing,
          or debate (viral/high-engagement human interest, controversy, celebrity, buzz).
          This is about POPULARITY, distinct from importance. Niche/routine items are FALSE.
        SYS;

        if (filled($custom)) {
            $system .= "\n\nHouse style & editorial guidance (follow closely):\n" . $custom;
        }

        // Data-driven: qualities learned from our own best-performing hooks. Apply
        // as guidance for the headline/opening — never copy any single one verbatim.
        $learned = Setting::get('learned_style_guide');
        if (filled($learned)) {
            $system .= "\n\nWhat works on our site (apply these hook qualities to the headline and opening; "
                . "do not copy any single example verbatim):\n" . $learned;
        }

        $user = "SOURCE TITLE: {$item['title']}\n\nSOURCE SUMMARY: {$item['summary']}";
        if (filled($fullText)) {
            $user .= "\n\nFULL SOURCE ARTICLE TEXT:\n{$fullText}";
        }

        $response = Http::withToken(trim($key))
            ->timeout(90)
            ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false) // transient blips only
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
                        'name' => 'rewritten_article',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'excerpt' => ['type' => 'string'],
                                'social_text' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'category' => ['type' => 'string', 'enum' => array_values($slugs)],
                                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'takeaways' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'faqs' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'question' => ['type' => 'string'],
                                            'answer' => ['type' => 'string'],
                                        ],
                                        'required' => ['question', 'answer'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                                'is_breaking' => ['type' => 'boolean'],
                                'is_top_story' => ['type' => 'boolean'],
                                'is_trending' => ['type' => 'boolean'],
                            ],
                            'required' => ['title', 'excerpt', 'social_text', 'body', 'category', 'tags', 'takeaways', 'faqs', 'is_breaking', 'is_top_story', 'is_trending'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ])
            ->throw();

        $content = data_get($response->json(), 'choices.0.message.content', '');
        $data = json_decode($content, true);

        if (! is_array($data) || blank($data['title'] ?? null)) {
            throw new \RuntimeException('AI returned unparseable output');
        }

        // One-shot expand: if the rewrite still lands under the publish floor AND
        // we have real source material to draw from, ask once to develop it to
        // length (grounded in the source, never invented). This turns "just under
        // 400w" near-misses into publishable articles instead of thin drafts.
        $body = trim($data['body'] ?? '');
        $minWords = (int) Setting::get('min_publish_words', 400);
        if (filled($fullText) && str_word_count(strip_tags($body)) < $minWords) {
            $expanded = $this->expandBody($body, (string) $fullText, $key, $minWords);
            if ($expanded && str_word_count(strip_tags($expanded)) > str_word_count(strip_tags($body))) {
                $body = $expanded;
            }
        }

        return [
            'title' => Str::limit(trim($data['title']), 200, ''),
            'excerpt' => \App\Support\ArticleSanitizer::cleanText(Str::limit(trim($data['excerpt'] ?? ''), 480, '')),
            'social_text' => \App\Support\ArticleSanitizer::cleanText(Str::limit(trim($data['social_text'] ?? ''), 300, '')),
            'body' => \App\Support\ArticleSanitizer::clean($body),
            'category' => $data['category'] ?? null,
            'tags' => array_values(array_filter(array_map('strval', (array) ($data['tags'] ?? [])))),
            'takeaways' => self::cleanTakeaways($data['takeaways'] ?? []),
            'faqs' => self::cleanFaqs($data['faqs'] ?? []),
            'is_breaking' => (bool) ($data['is_breaking'] ?? false),
            'is_top_story' => (bool) ($data['is_top_story'] ?? false),
            'is_trending' => (bool) ($data['is_trending'] ?? false),
        ];
    }

    /**
     * One follow-up call to develop a too-short rewrite to length, using ONLY
     * the source material + widely-known context (never inventing specifics).
     * Returns the expanded HTML body, or null on failure.
     */
    private function expandBody(string $currentBody, string $fullText, string $key, int $minWords): ?string
    {
        set_time_limit(120);
        $target = max($minWords + 150, 550);

        $system = "You are a news editor. Expand the DRAFT below into a fuller, well-structured news article "
            . "of AT LEAST {$minWords} words (aim for about {$target}), in 6-9 paragraphs wrapped in <p></p> tags. "
            . "Develop it with genuine background, context, and significance drawn ONLY from the source material and "
            . "widely-known general context — never invent quotes, numbers, dates, or names, and never pad with "
            . "repetition. Keep it factual and neutral. Do NOT add meta-commentary, SEO notes, headings, or any "
            . "reference to sources, summaries, or being an AI. Return ONLY the expanded article body as HTML.";

        try {
            $response = Http::withToken(trim($key))
                ->timeout(90)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "SOURCE MATERIAL:\n{$fullText}\n\nDRAFT TO EXPAND:\n{$currentBody}"],
                    ],
                ])
                ->throw();

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('Body expand failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Normalize AI takeaways: strip tags/leading dashes, drop empties, cap at 4.
     *
     * @return array<int,string>
     */
    public static function cleanTakeaways($raw): array
    {
        $out = [];
        foreach ((array) $raw as $t) {
            $t = \App\Support\ArticleSanitizer::cleanText(ltrim(trim(strip_tags((string) $t)), "-•* \t"));
            if ($t !== '') {
                $out[] = Str::limit($t, 200, '');
            }
        }

        return array_slice(array_values($out), 0, 4);
    }

    /**
     * Normalize AI FAQs into [{question, answer}], dropping any incomplete pair.
     *
     * @return array<int,array{question:string,answer:string}>
     */
    public static function cleanFaqs($raw): array
    {
        $out = [];
        foreach ((array) $raw as $f) {
            $q = \App\Support\ArticleSanitizer::cleanText(trim(strip_tags((string) ($f['question'] ?? ''))));
            $a = \App\Support\ArticleSanitizer::cleanText(trim(strip_tags((string) ($f['answer'] ?? ''))));
            if ($q !== '' && $a !== '') {
                $out[] = ['question' => Str::limit($q, 180, ''), 'answer' => Str::limit($a, 600, '')];
            }
        }

        return array_slice($out, 0, 4);
    }

    /** Deterministic non-AI fallback: light reformat + clear notice. */
    private function stub(array $item): array
    {
        $summary = $item['summary'] ?: $item['title'];

        return [
            'title' => $item['title'],
            'excerpt' => Str::limit($summary, 180),
            'social_text' => null,
            'body' => '<p>' . e($summary) . '</p>'
                . '<p><em>This is an automated draft awaiting AI rewriting. '
                . 'The AI request failed or is not configured — check the API key '
                . 'and your OpenAI account credits in AI &amp; Ads Settings.</em></p>',
            'category' => null,
            'tags' => [],
            'takeaways' => [],
            'faqs' => [],
            'is_breaking' => false,
            'is_top_story' => false,
            'is_trending' => false,
        ];
    }
}
