<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Raises a single post's on-page SEO to target: concise title-derived focus
 * keyword, proper meta, H2 subheadings, keyword in the intro, readability,
 * validated links — then re-scores. Facts and the title/slug are never changed.
 *
 * Shared by the posts:seo-boost bulk command (past posts) and IngestService
 * (new posts that land under target after the normal rewrite/optimize).
 */
class SeoBooster
{
    public function __construct(
        private SeoAnalyzer $analyzer,
        private LinkEnricher $links,
    ) {}

    /**
     * Boost one post in place. Returns the new SEO score, or the current score
     * unchanged when there is no API key or the AI returns nothing.
     */
    public function boost(Post $post): int
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return (int) ($post->seo_score ?? 0);
        }

        $seoData = $this->generate($post, (string) $key);
        if ($seoData) {
            // Guard: never let the SEO pass silently drop article content.
            $oldWords = str_word_count(strip_tags((string) $post->body));
            $newWords = str_word_count(strip_tags($seoData['body']));
            $bodyOk = $newWords >= (int) floor($oldWords * 0.85);

            $post->forceFill([
                'body' => $bodyOk ? $seoData['body'] : $post->body,
                'focus_keyword' => $seoData['focus_keyword'],
                'meta_title' => $seoData['meta_title'],
                'meta_description' => $seoData['meta_description'],
            ])->saveQuietly();
        }

        // Validated internal/external links on the new body.
        try {
            $this->links->enrichPost($post);
        } catch (\Throwable) {
            // non-fatal — the fallback below still guarantees one link
        }

        // Guarantee at least one internal link (otherwise the link check hard-fails).
        if (substr_count(strtolower((string) $post->body), '<a ') === 0) {
            $rel = Post::published()->where('category_id', $post->category_id)
                ->where('id', '!=', $post->id)->latest('published_at')->first();
            if ($rel) {
                $post->body = (string) $post->body
                    . '<p class="related-inline">Related: <a href="' . route('post.show', $rel) . '">'
                    . e($rel->title) . '</a></p>';
                $post->saveQuietly();
            }
        }

        $analysis = $this->analyzer->analyze(
            $post->title, $post->body, $post->meta_title,
            $post->meta_description, $post->focus_keyword, route('post.show', $post),
            filled($post->featured_image),
        );
        $post->forceFill([
            'seo_score' => $analysis['score'],
            'seo_analysis' => $analysis,
            'seo_analyzed_at' => now(),
        ])->saveQuietly();

        return (int) ($analysis['score'] ?? 0);
    }

    /**
     * One AI call: concise title-derived keyword, optimized meta, and the SAME
     * article restructured with H2 subheadings + keyword in the intro + simpler
     * sentences (no fact changes, title unchanged). Very long bodies: meta only.
     *
     * @return array{focus_keyword:string,meta_title:string,meta_description:string,body:string}|null
     */
    private function generate(Post $post, string $key): ?array
    {
        $body = (string) $post->body;
        $restructure = mb_strlen($body) <= 14000;

        $sys = <<<SYS
        You are an on-page SEO editor for a US news site. Improve the article's SEO
        WITHOUT changing any facts, quotes, numbers, names, or its meaning, and WITHOUT
        changing the article title. Return JSON:
        - focus_keyword: a SIMPLE 2-3 word search phrase taken VERBATIM from the TITLE
          (the main person/place/topic a reader would type). No punctuation, never 4+
          words, never a sentence.
        - meta_title: <=60 characters, compelling, MUST contain the focus_keyword.
        - meta_description: 130-160 characters, MUST contain the focus_keyword, and end
          like a call to action.
        - body: %s Return valid HTML using only <p> and <h2> tags. Keep EVERY existing
          paragraph and all facts. %s
        SYS;
        $bodyRule = $restructure
            ? 'the SAME article, same paragraphs and facts, but: (1) use the exact focus_keyword phrase in the FIRST paragraph and 3-4 times total across the body, naturally (about 1% density); (2) insert 2-3 descriptive <h2> subheadings between paragraph groups (never at the very top; keep a lead paragraph first); and (3) REWRITE FOR READABILITY to an 8th-grade level — keep almost every sentence under 18 words, split long/run-on sentences, use plain everyday words, active voice (aim Flesch 60+). Do NOT change any facts, quotes, numbers, names, or the meaning.'
            : 'return the body UNCHANGED, exactly as given.';
        $system = sprintf($sys, $restructure ? 'rewrite' : 'echo', $bodyRule);

        $response = Http::withToken(trim($key))->timeout(90)
            ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('openai_model', config('services.openai.model')),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => 'TITLE: ' . $post->title . "\n\nBODY HTML:\n" . $body],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'seo_boost',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'focus_keyword' => ['type' => 'string'],
                                'meta_title' => ['type' => 'string'],
                                'meta_description' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                            ],
                            'required' => ['focus_keyword', 'meta_title', 'meta_description', 'body'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ])->throw();

        $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);
        if (! is_array($data) || blank($data['focus_keyword'] ?? null)) {
            return null;
        }

        return [
            'focus_keyword' => Str::limit(trim($data['focus_keyword']), 60, ''),
            'meta_title' => Str::limit(trim($data['meta_title']), 60, ''),
            'meta_description' => Str::limit(trim($data['meta_description']), 170, ''),
            'body' => trim($data['body']) ?: $body,
        ];
    }
}
