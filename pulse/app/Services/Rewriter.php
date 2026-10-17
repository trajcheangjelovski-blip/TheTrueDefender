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
            'story-of-hope' => 'ONLY genuinely uplifting human-interest stories with a positive outcome (rescues, kindness, recoveries, achievements, community wins). NEVER deaths, obituaries, tragedies, accidents, crime, disasters, celebrity gossip or controversy — those go to us-news or world',
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
        - 65-80: genuinely UPLIFTING, verifiable American human-interest stories (our
          "Story of Hope" section) — real, specific people or communities with a positive
          outcome: rescues, generosity, recoveries, comebacks, community wins. Uplifting
          stories with no US angle: 50-64. Never score a death, tragedy or crime as uplifting.
        - 70-85: MAJOR WORLD news Americans need to know (our "World" section) — wars and
          military escalation, international crises, major foreign elections or leadership
          changes, big global disasters, especially with a US angle. Routine foreign news
          with no US angle stays in 0-39.
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

    /**
     * Multi-source synthesis: fold a NEW outlet's report on the SAME story into
     * the existing article, adding only genuinely new facts/quotes (grounded,
     * never invented) and keeping all existing content and structure. Returns the
     * enriched HTML body, or null on failure / when the new source adds nothing.
     */
    public function mergeSource(string $existingTitle, string $existingBody, string $newSourceText, string $newSourceName): ?string
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key) || blank(trim(strip_tags($newSourceText)))) {
            return null;
        }

        try {
            set_time_limit(120);
            $system = <<<SYS
            You are a news editor. You have an EXISTING article and a NEW report from
            another outlet about the SAME story. Produce an updated version of the
            existing article that FOLDS IN any genuinely new facts, figures, named
            quotes, or details from the new report that are not already present.
            Rules:
            - Keep ALL existing facts, paragraphs and <h2> subheadings. Never remove content.
            - Add ONLY details grounded in the new report. NEVER invent anything.
            - Do NOT duplicate information already in the article; integrate new details
              smoothly into the relevant section, or add a short new paragraph.
            - Preserve the article's focus, structure and 8th-grade readability. Never repeat a sentence
              or phrase already in the article.
            - If the new report genuinely adds nothing, return the article UNCHANGED.
            - NEVER output placeholder/template tokens (e.g. [[LINK]], [LINK], {{source}}, [citation needed]),
              and never claim WE contacted anyone or that a source "did not respond to a request for comment".
            - Integrate new facts DIRECTLY into the prose. NEVER refer to "the new report", "the report says",
              "according to the report", or "the article says" in the OUTPUT — write each fact as your own.
            - A development that happened AFTER the article was first written must go in a paragraph at the
              end beginning "Update (Month Day, Year):" — never silently rewrite what the article reported.
            - Return the FULL updated body as valid HTML using only <p> and <h2> tags.
            SYS;
            $system = \App\Support\EditorialPolicy::forPrompt() . "\n\n=== THIS TASK ===\n" . $system;
            $user = "EXISTING ARTICLE TITLE: {$existingTitle}\n\nEXISTING ARTICLE BODY:\n{$existingBody}\n\n"
                . "NEW REPORT (from {$newSourceName}):\n" . Str::limit(strip_tags($newSourceText), 6000, '');

            $response = Http::withToken(trim($key))->timeout(90)
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
                            'name' => 'merged_article',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['body' => ['type' => 'string']],
                                'required' => ['body'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);
            $body = trim($data['body'] ?? '');

            return $body !== '' ? \App\Support\ArticleSanitizer::clean($body) : null;
        } catch (\Throwable $e) {
            Log::warning('Source merge failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * THE NEWS WRITER (pipeline stages 4 and 7), under the editorial policy.
     *
     * One method, three jobs:
     *  - mode "new":      write a news article from verified research;
     *  - mode "revision": rewrite a PUBLISHED article as of its original date,
     *                     correcting only errors the sources confirm;
     *  - mode "fix":      revise a draft to resolve specific fact-check findings.
     *
     * $brief: title, category, sources[{name,url,text,title?,published_at?}],
     *         as_of (Y-m-d|null), existing (plain text, revisions), known_issues
     *         (string, revisions), draft (html, fix), feedback (string, fix).
     *
     * @return array{story_type:string,headline:string,excerpt:string,body:string,takeaways:array<int,string>,meta_title:string,meta_description:string,corrections:array<int,string>,change_summary:string}|null
     */
    public function writeArticle(\App\Services\AiClient $ai, string $mode, array $brief): ?array
    {
        $sources = array_values(array_filter((array) ($brief['sources'] ?? []), fn ($s) => filled(trim(strip_tags((string) ($s['text'] ?? ''))))));
        if (! $sources && $mode !== 'fix') {
            return null;
        }

        $research = '';
        foreach (array_slice($sources, 0, 5) as $i => $s) {
            $research .= "\n\nSOURCE " . ($i + 1) . ' — ' . ($s['name'] ?? 'source')
                . (filled($s['published_at'] ?? null) ? ' (published ' . $s['published_at'] . ')' : '')
                . (! empty($s['later']) ? ' [PUBLISHED OR UPDATED AFTER OUR ARTICLE — facts found only here are later developments]' : '')
                . (filled($s['url'] ?? null) ? ' — ' . $s['url'] : '') . "\n"
                . Str::limit(trim(strip_tags((string) $s['text'])), $i === 0 ? 5500 : 3500, ' […]');
        }

        $task = match ($mode) {
            'revision' => 'TASK: Rewrite the PUBLISHED article below so it meets the editorial policy, written as of the '
                . 'moment it was originally published (' . ($brief['as_of'] ?? 'unknown') . '). Use the sources to verify every '
                . 'fact. The events may predate that moment — keep their dates. Anything the sources date AFTER that moment '
                . 'may appear only in a final paragraph beginning "Update (Month Day, Year):" — and only if readers genuinely '
                . 'need it. Remove any "Our Take", "Analysis" or other opinion section — this is a news article. Keep the '
                . 'subject, category and every accurate, useful fact. Where sources conflict, attribute each version.'
                . "\n\nCORRECTIONS: a correction is a fact the published version got WRONG where the sources agree on the "
                . 'right one. Report each in "corrections" with the number of the finding below that it fixes and one plain '
                . 'sentence for readers ("An earlier version of this article said X; Y."). Removing an unsupported detail, '
                . 'tightening wording, adding attribution or removing opinion are EDITS, not corrections — describe those in '
                . "change_summary only.\n\nFINDINGS ON THE PUBLISHED VERSION (verify each against the sources before "
                . "changing anything):\n" . (($brief['known_issues'] ?? '') ?: '(none found)')
                . "\n\nPUBLISHED ARTICLE:\n" . Str::limit((string) ($brief['existing'] ?? ''), 7000, ''),
            'fix' => 'TASK: Revise the DRAFT below to resolve every fact-check finding listed. Change nothing else unless '
                . 'a fix requires it. Where the sources cannot settle a point, remove the claim or attribute it clearly. '
                . "Keep the same structure and story type.\n\nFACT-CHECK FINDINGS:\n" . ($brief['feedback'] ?? '')
                . "\n\nDRAFT:\n" . (string) ($brief['draft'] ?? ''),
            default => 'TASK: Write a news article about the story below from the research provided. Choose the story '
                . 'type and structure that fit THIS story (policy section 6). Corroborate key facts across sources where '
                . 'possible, attribute what only one source reports, and report genuine conflicts.',
        };

        $system = \App\Support\EditorialPolicy::forPrompt() . "\n\n=== OUTPUT ===\n"
            . "- body: valid HTML using only <p> and <h2> tags (zero to four <h2>, specific to this story).\n"
            . "- headline: accurate and specific, roughly 50–75 characters, no clickbait.\n"
            . "- excerpt: one or two sentences (under 300 characters) stating the most important fact.\n"
            . "- takeaways: zero to four short bullets, only when the story has distinct points worth summarizing.\n"
            . "- meta_title: up to 60 characters. meta_description: about 140–160 characters.\n"
            . "- story_type: the structure you used.\n"
            . "- corrections: confirmed factual corrections, each with the finding number it fixes (revision mode only; otherwise empty).\n"
            . "- change_summary: one or two sentences on what you changed and why (revision/fix modes; otherwise \"\").\n"
            . $this->recentPhrasingToAvoid();

        $user = $task . "\n\nWORKING HEADLINE: " . ($brief['title'] ?? '') . "\nSECTION: " . ($brief['category'] ?? 'news')
            . ($research !== '' ? "\n\n=== RESEARCH ===" . $research : '');

        $stage = match ($mode) { 'fix' => 'article_fix', 'revision' => 'article_revision', default => 'article_write' };
        $data = $ai->json($stage, $system, $user, [
            'story_type' => ['type' => 'string', 'enum' => ['breaking', 'political', 'crime', 'world', 'explainer', 'human_interest']],
            'headline' => ['type' => 'string'],
            'excerpt' => ['type' => 'string'],
            'body' => ['type' => 'string'],
            'takeaways' => ['type' => 'array', 'items' => ['type' => 'string']],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'corrections' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['issue' => ['type' => 'integer'], 'text' => ['type' => 'string']],
                'required' => ['issue', 'text'],
                'additionalProperties' => false,
            ]],
            'change_summary' => ['type' => 'string'],
        ], null, 180);

        $body = $data ? \App\Support\ArticleSanitizer::clean(trim((string) ($data['body'] ?? ''))) : '';
        if ($body === '' || str_word_count(strip_tags($body)) < 200) {
            return null;
        }

        return [
            'story_type' => (string) $data['story_type'],
            'headline' => Str::limit(trim(strip_tags((string) $data['headline'])), 200, ''),
            'excerpt' => \App\Support\ArticleSanitizer::cleanText(Str::limit(trim((string) $data['excerpt']), 480, '')),
            'body' => $body,
            'takeaways' => self::cleanTakeaways($data['takeaways'] ?? []),
            'meta_title' => Str::limit(trim((string) $data['meta_title']), 60, ''),
            'meta_description' => Str::limit(trim((string) $data['meta_description']), 170, ''),
            // [{issue, text}] — EditorialPipeline keeps only those that match a verified, correctable finding.
            'corrections' => array_values(array_filter(array_map(
                fn ($c) => is_array($c) && filled(trim(strip_tags((string) ($c['text'] ?? ''))))
                    ? ['issue' => (int) ($c['issue'] ?? 0), 'text' => trim(strip_tags((string) $c['text']))] : null,
                (array) ($data['corrections'] ?? []),
            ))),
            'change_summary' => trim((string) ($data['change_summary'] ?? '')),
        ];
    }

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
            'story-of-hope' => 'ONLY genuinely uplifting human-interest stories with a positive outcome (rescues, generosity, recoveries, comebacks, community wins). NEVER deaths, obituaries, tragedies, accidents, crime, disasters, celebrity gossip or controversy — classify those as us-news or world',
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
              . 'do not invent them. If the source is brief, still develop the background and significance to length. '
              . 'STRUCTURE: break the body with 2-3 <h2> subheadings placed between paragraph groups — NOT at the '
              . 'very top — each specific to THIS story\'s facts (never generic labels like "Background & Context" or '
              . '"What Happens Next"). Keep the opening as a lead paragraph before the first <h2>; its first sentence '
              . 'must open with a new fact, never restate the headline. Never repeat a sentence or phrase.'
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
        - NEVER output placeholder or template tokens of any kind — e.g. [[LINK]], [LINK], [URL],
          {{source}}, [citation needed], [insert quote], [image]. Write the finished words only; if you
          would link a phrase, just write the plain phrase. A stray marker like "[[LINK]]" must never appear.
        - You did NOT conduct any interviews or outreach. NEVER write that this outlet contacted, reached
          out to, emailed, called, or interviewed anyone, and never write that a person or agency "did not
          respond to a request for comment" as if WE sought comment. Only mention that comment was sought
          if you attribute it explicitly to the original outlet (e.g. "Reuters reported it had contacted…").
        - Report the facts DIRECTLY, as your own finished story. NEVER frame the piece as describing another
          outlet's article: do not write "the new report says", "the report says/adds", "according to the
          report", "the article says", or "the source article". State each fact plainly, not as something a
          report claims.
        - {$lengthRule}
        - Write a fresh headline (not identical to the source) that ACCURATELY and specifically describes
          what happened — who did what. It must be fully supported by the story. NO clickbait or sensational
          wording: avoid "slams", "rips", "destroys", "blasts", "shocking", "you won't believe", all-caps, or
          questions that tease instead of inform. Then a one-sentence factual excerpt.
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

        Also return "takeaways": ZERO to four short bullet points, only when the story has
        distinct points worth summarizing — never pad to a fixed number. One sentence each,
        ≤22 words, no leading dashes, factual and self-contained. Grounded ONLY in the article —
        never invent. These render as a summary box, so they must stand alone without the article.

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

        SEO (required — the story must be search-optimized from the start):
        - focus_keyword: a SIMPLE 2-3 word search phrase that appears VERBATIM in your
          headline (the main person/place/topic a reader would type). No punctuation,
          never 4+ words, never a sentence.
        - Do NOT stuff the focus_keyword into the body or repeat it; mention the subject
          naturally where it fits. The first sentence must never restate the headline.
        - meta_title: <=60 characters, compelling, MUST contain the focus_keyword.
        - meta_description: about 140-160 characters, stating the most important fact
          (naturally containing the focus_keyword).
        - READABILITY: clear, natural American English; mix short and longer sentences;
          plain words; active voice.
        SYS;

        // The permanent editorial policy (+ admin house-voice notes) governs this
        // draft too; it overrides anything above that conflicts with it.
        $system = \App\Support\EditorialPolicy::forPrompt() . "\n\n=== THIS TASK ===\n" . $system;

        // Data-driven: qualities learned from our own best-performing hooks —
        // subordinate to the policy (accuracy and the no-clickbait rules always win).
        $learned = Setting::get('learned_style_guide');
        if (filled($learned)) {
            $system .= "\n\nLearned headline/opening qualities (apply only where consistent with the editorial "
                . "policy; never copy an example verbatim; never clickbait or sensational wording):\n" . $learned;
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
                                'focus_keyword' => ['type' => 'string'],
                                'meta_title' => ['type' => 'string'],
                                'meta_description' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'excerpt', 'social_text', 'body', 'category', 'tags', 'takeaways', 'faqs', 'is_breaking', 'is_top_story', 'is_trending', 'focus_keyword', 'meta_title', 'meta_description'],
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
            'focus_keyword' => Str::limit(trim((string) ($data['focus_keyword'] ?? '')), 60, ''),
            'meta_title' => Str::limit(trim((string) ($data['meta_title'] ?? '')), 60, ''),
            'meta_description' => Str::limit(trim((string) ($data['meta_description'] ?? '')), 170, ''),
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
            . "reference to sources, summaries, or being an AI. NEVER output placeholder tokens (e.g. [[LINK]], "
            . "[LINK], {{source}}) and never claim we contacted anyone or that a source did not respond to a "
            . "request for comment. Return ONLY the expanded article body as HTML.";

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

    /** Plain text of an article's "Our Take" section (without the Related link line). */
    public static function takeText(string $html): string
    {
        $html = preg_replace('/<p[^>]*class="[^"]*related-inline[^"]*"[^>]*>.*?<\/p>/is', ' ', $html);
        if (! preg_match('/<h2[^>]*>\s*Our Take\s*<\/h2>(.*)$/is', $html, $m)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</p>', ' </p>', $m[1])))));
    }

    /**
     * Openings and "Our Take" first lines of our most recent articles, so a new
     * article never reuses their wording. Returns a prompt block ('' if none).
     */
    private function recentPhrasingToAvoid(): string
    {
        $firstSentence = fn (string $text) => trim(Str::before(trim($text) . ' ', '. '));

        $lines = \App\Models\Post::where('status', 'published')->latest('published_at')->take(12)->pluck('body')
            ->flatMap(function ($body) use ($firstSentence) {
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</p>', ' </p>', (string) $body))));
                $take = self::takeText((string) $body);
                $takeSentences = $take !== '' ? (preg_split('/(?<=[.!?])\s+/', $take) ?: []) : [];

                return array_filter([
                    $firstSentence($plain),
                    $takeSentences[0] ?? null,                                   // how it opens
                    count($takeSentences) > 1 ? end($takeSentences) : null,      // how it ends
                ]);
            })
            ->filter(fn ($s) => mb_strlen($s) >= 25)
            ->map(fn ($s) => '  • ' . Str::limit($s, 160))
            ->unique()->values();

        if ($lines->isEmpty()) {
            return '';
        }

        return "- Do not reuse the wording or sentence pattern of these openings and \"Our Take\" lines from\n"
            . "  our recent articles:\n" . $lines->implode("\n") . "\n";
    }
}
