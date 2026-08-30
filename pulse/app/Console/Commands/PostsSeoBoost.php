<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\LinkEnricher;
use App\Services\SeoAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PostsSeoBoost extends Command
{
    protected $signature = 'posts:seo-boost
        {--target=80 : Only touch posts scoring below this}
        {--limit=25 : Max posts to process this run}
        {--order=score : score|oldest|views — which posts first}
        {--sleep=1 : Seconds to pause between posts}
        {--dry : Show intended changes, write nothing}';

    protected $description = 'Raise on-page SEO of low-scoring posts to the target: concise title-derived focus keyword, proper meta title/description, H2 subheadings, keyword in the intro, validated links — then re-score. Facts and titles are never changed.';

    public function handle(SeoAnalyzer $analyzer, LinkEnricher $links): int
    {
        $target = (int) $this->option('target');
        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dry = (bool) $this->option('dry');

        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured — aborting.');
            return self::FAILURE;
        }

        $q = Post::published()->where(function ($w) use ($target) {
            $w->whereNull('seo_score')->orWhere('seo_score', '<', $target);
        });
        $q = match ($this->option('order')) {
            'oldest' => $q->orderBy('published_at'),
            'views' => $q->orderByDesc('views'),
            default => $q->orderBy('seo_score'),   // worst first
        };
        $posts = $q->limit($limit)->get();

        if ($posts->isEmpty()) {
            $this->info("No published posts under SEO {$target}. Nothing to do.");
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY] ' : '') . "SEO-boosting {$posts->count()} post(s) below {$target}…");
        $this->newLine();

        $improved = $stillLow = $errors = 0;

        foreach ($posts as $i => $post) {
            $old = (int) ($post->seo_score ?? 0);
            $label = '[' . ($i + 1) . '/' . $posts->count() . '] #' . $post->id . ' ' . Str::limit($post->title, 50);

            try {
                $seoData = $this->generate($post, (string) $key);
                if (! $seoData) {
                    $this->line("  ! {$label} — AI returned nothing, skipped");
                    $errors++;
                    if ($sleep && $i < $posts->count() - 1) sleep($sleep);
                    continue;
                }

                if ($dry) {
                    $this->line("  ~ {$label} — kw=\"{$seoData['focus_keyword']}\" · h2s=" . substr_count(strtolower($seoData['body']), '<h2') . " · would re-optimize (was {$old})");
                    $improved++;
                    if ($sleep && $i < $posts->count() - 1) sleep($sleep);
                    continue;
                }

                // Guard: never let the SEO pass silently drop article content.
                $oldWords = str_word_count(strip_tags((string) $post->body));
                $newWords = str_word_count(strip_tags($seoData['body']));
                $bodyOk = $newWords >= (int) floor($oldWords * 0.85);

                $post->forceFill([
                    'body' => $bodyOk ? $seoData['body'] : $post->body,   // keep old body if new one lost content
                    'focus_keyword' => $seoData['focus_keyword'],
                    'meta_title' => $seoData['meta_title'],
                    'meta_description' => $seoData['meta_description'],
                ])->saveQuietly();

                // Add validated internal/external links to the new body.
                try {
                    $links->enrichPost($post);
                } catch (\Throwable $e) {
                    // non-fatal — the fallback below still guarantees one link
                }

                // Guarantee at least one internal link (the link check is otherwise a
                // hard fail): append a contextual "Related" link to a real recent post
                // in the same category when nothing else stuck.
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

                // Score once, telling the analyzer the page has a featured (hero) image.
                $analysis = $analyzer->analyze(
                    $post->title, $post->body, $post->meta_title,
                    $post->meta_description, $post->focus_keyword, route('post.show', $post),
                    filled($post->featured_image),
                );
                $post->forceFill([
                    'seo_score' => $analysis['score'],
                    'seo_analysis' => $analysis,
                    'seo_analyzed_at' => now(),
                ])->saveQuietly();
                $new = (int) ($analysis['score'] ?? 0);

                if ($new >= $target) {
                    $this->info("  ✓ {$label} — {$old} → {$new}");
                    $improved++;
                } else {
                    $this->line("  · {$label} — {$old} → {$new} (still under {$target})");
                    $stillLow++;
                }
            } catch (\Throwable $e) {
                $this->line("  ! {$label} — error: " . Str::limit($e->getMessage(), 90));
                $errors++;
            }

            if ($sleep && $i < $posts->count() - 1) {
                sleep($sleep);
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Done — reached target: {$improved}, still under: {$stillLow}, errors: {$errors}");

        return self::SUCCESS;
    }

    /**
     * One AI call: concise title-derived keyword, optimized meta, and the SAME
     * article restructured with H2 subheadings + keyword in the intro (no fact
     * changes, title unchanged). Body is only restructured when it's not too long.
     *
     * @return array{focus_keyword:string,meta_title:string,meta_description:string,body:string}|null
     */
    private function generate(Post $post, string $key): ?array
    {
        $body = (string) $post->body;
        $restructure = mb_strlen($body) <= 14000; // very long posts: meta/keyword only

        $sys = <<<SYS
        You are an on-page SEO editor for a US news site. Improve the article's SEO
        WITHOUT changing any facts, quotes, numbers, names, or its meaning, and WITHOUT
        changing the article title. Return JSON:
        - focus_keyword: a SIMPLE 2-3 word search phrase taken VERBATIM from the TITLE
          (the main person/place/topic a reader would type). No punctuation, no
          apostrophes/hyphens, never 4+ words, never a sentence.
        - meta_title: <=60 characters, compelling, MUST contain the focus_keyword.
        - meta_description: 130-160 characters, MUST contain the focus_keyword, and end
          like a call to action.
        - body: %s Return valid HTML using only <p> and <h2> tags. Keep EVERY existing
          paragraph and all facts. %s
        SYS;
        $bodyRule = $restructure
            ? 'the SAME article, same paragraphs and facts, but: (1) use the exact focus_keyword phrase in the FIRST paragraph and 3-4 times total across the body, naturally (about 1% density); (2) insert 2-3 descriptive <h2> subheadings between paragraph groups (never at the very top; keep a lead paragraph first); and (3) REWRITE FOR READABILITY to an 8th-grade level — this is REQUIRED: keep almost every sentence under 18 words, split all long/run-on sentences into shorter ones, use plain everyday words instead of jargon or officialese, and use active voice. Aim for a Flesch Reading Ease of 60+. Do NOT change any facts, quotes, numbers, names, or the meaning.'
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
