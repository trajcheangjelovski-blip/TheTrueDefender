<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('opinions:generate {--count=3 : How many opinion topics to create}')]
#[Description('Generate Opinion-forum discussion posts grounded in recent US news the site has covered')]
class OpinionsGenerate extends Command
{
    public function handle(): int
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured.');

            return self::FAILURE;
        }

        $opinion = Category::where('slug', 'opinion')->first();
        if (! $opinion) {
            $this->error('Opinion category not found.');

            return self::FAILURE;
        }

        $author = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first() ?? User::first();
        $count = max(1, (int) $this->option('count'));

        // Ground the opinions in recent US-relevant news the site has published.
        $usCatIds = Category::whereIn('slug', ['politics', 'us-news', 'world'])->pluck('id');
        $topics = Post::published()->whereNotNull('source_url')
            ->whereIn('category_id', $usCatIds)
            ->latest('published_at')
            ->take($count * 2)->get(['title', 'excerpt']);

        // Avoid repeating topics we've already turned into opinion posts.
        $existing = Post::where('category_id', $opinion->id)->pluck('title')->map(fn ($t) => Str::lower($t));

        $made = 0;
        foreach ($topics as $topic) {
            if ($made >= $count) {
                break;
            }
            $op = $this->write($topic->title, (string) $topic->excerpt, $key);
            if (! $op || $existing->contains(Str::lower($op['title']))) {
                continue;
            }

            Post::create([
                'title' => $op['title'],
                'slug' => $this->uniqueSlug($op['title']),
                'excerpt' => $op['excerpt'],
                'body' => $op['body'],
                'category_id' => $opinion->id,
                'author_id' => $author?->id,
                'image_icon' => '💬',
                'status' => 'published',
                'published_at' => now(),
                'allow_comments' => true,
                // Suppress push/social fan-out for batch-generated forum topics.
                'push_notified_at' => now(),
                'social_posted_at' => now(),
            ]);
            $this->info('Created opinion: ' . $op['title']);
            $made++;
        }

        $this->info("Generated {$made} opinion topic(s).");

        return self::SUCCESS;
    }

    /** @return array{title:string,excerpt:string,body:string}|null */
    private function write(string $sourceTitle, string $sourceExcerpt, string $key): ?array
    {
        set_time_limit(120);
        $site = config('app.name', 'TheTrueDefender');
        $custom = Setting::get('ai_instructions');

        $system = <<<SYS
        You are an opinion columnist for "{$site}", an independent US news outlet.
        Write a SHORT opinion/editorial discussion-starter based on the current news topic below.
        Rules:
        - Take a clear, thoughtful editorial stance — this is opinion, clearly labeled as such.
        - Do NOT invent facts, quotes, or statistics. Reason from the topic; keep specific claims general.
        - Be respectful and civil. No hate, no personal attacks, no defamation, no calls to action against anyone.
        - Headline: an engaging question or a bold-but-fair stance (<= 12 words).
        - Body: 2-3 short paragraphs (<=180 words) in <p></p> tags, ENDING by inviting readers to share their view.
        - Excerpt: one sentence teasing the debate.
        SYS;
        if (filled($custom)) {
            $system .= "\n\nHouse style (follow closely):\n" . $custom;
        }

        try {
            $r = Http::withToken(trim($key))->timeout(60)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "NEWS TOPIC: {$sourceTitle}\n\nCONTEXT: {$sourceExcerpt}"],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'opinion',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'excerpt' => ['type' => 'string'],
                                    'body' => ['type' => 'string'],
                                ],
                                'required' => ['title', 'excerpt', 'body'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $data = json_decode(data_get($r->json(), 'choices.0.message.content', ''), true);

            return is_array($data) && filled($data['title'] ?? null) ? [
                'title' => Str::limit(trim($data['title']), 200, ''),
                'excerpt' => Str::limit(trim($data['excerpt'] ?? ''), 480, ''),
                'body' => trim($data['body'] ?? ''),
            ] : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Opinion generation failed: ' . $e->getMessage());

            return null;
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'opinion';
        $slug = $base;
        $i = 2;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
