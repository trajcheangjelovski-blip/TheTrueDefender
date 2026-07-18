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
    public function rewrite(array $item, string $categoryName, string $sourceName): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));

        if (blank($key)) {
            return $this->stub($item);
        }

        try {
            return $this->viaOpenAI($item, $categoryName, $sourceName, $key);
        } catch (\Throwable $e) {
            Log::warning('AI rewrite failed, using stub: ' . $e->getMessage());
            return $this->stub($item);
        }
    }

    private function viaOpenAI(array $item, string $categoryName, string $sourceName, string $key): array
    {
        // AI calls can outlive the web server's 30s limit — extend per call.
        set_time_limit(180);

        $site = config('app.name', 'TheTrueDefender');
        $custom = Setting::get('ai_instructions'); // your custom editorial guidance, taught in the admin
        $fullText = $item['full_text'] ?? null;    // full source article, when the fetch succeeded

        // With the full article we can write a complete original story;
        // with only the RSS snippet we stay short so nothing gets invented.
        $lengthRule = filled($fullText)
            ? 'Body: a COMPLETE news article of 4-7 paragraphs, ~350-600 words total, wrapped in <p></p> HTML tags. Cover all the key facts, context, and reactions present in the source text.'
            : 'Body: 2-3 short paragraphs, ~120-220 words total, wrapped in <p></p> HTML tags.';

        $system = <<<SYS
        You are a news editor for "{$site}", an independent US news outlet.
        Rewrite the source material below into an ORIGINAL news article in your own words.
        Rules:
        - Do NOT copy sentences or distinctive phrasing from the source; write fresh, neutral, factual prose.
        - Only use facts present in the provided source material. Do not invent quotes, numbers, or details.
        - {$lengthRule}
        - Write a fresh, punchy headline (not identical to the source) and a one-sentence excerpt.
        - Category context: {$categoryName}. Source: {$sourceName} (attribution is added separately).

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
                                'body' => ['type' => 'string'],
                                'is_breaking' => ['type' => 'boolean'],
                                'is_top_story' => ['type' => 'boolean'],
                                'is_trending' => ['type' => 'boolean'],
                            ],
                            'required' => ['title', 'excerpt', 'body', 'is_breaking', 'is_top_story', 'is_trending'],
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

        return [
            'title' => Str::limit(trim($data['title']), 200, ''),
            'excerpt' => Str::limit(trim($data['excerpt'] ?? ''), 480, ''),
            'body' => trim($data['body'] ?? ''),
            'is_breaking' => (bool) ($data['is_breaking'] ?? false),
            'is_top_story' => (bool) ($data['is_top_story'] ?? false),
            'is_trending' => (bool) ($data['is_trending'] ?? false),
        ];
    }

    /** Deterministic non-AI fallback: light reformat + clear notice. */
    private function stub(array $item): array
    {
        $summary = $item['summary'] ?: $item['title'];

        return [
            'title' => $item['title'],
            'excerpt' => Str::limit($summary, 180),
            'body' => '<p>' . e($summary) . '</p>'
                . '<p><em>This is an automated draft awaiting AI rewriting. '
                . 'The AI request failed or is not configured — check the API key '
                . 'and your OpenAI account credits in AI &amp; Ads Settings.</em></p>',
            'is_breaking' => false,
            'is_top_story' => false,
            'is_trending' => false,
        ];
    }
}
