<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('opinions:generate {--count=3 : How many opinion topics to create}')]
#[Description('Generate Opinion-forum discussion posts grounded in recent US news the site has covered')]
class OpinionsGenerate extends Command
{
    public function handle(ImageService $images): int
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

        // Base opinions on the TOP stories of the day: featured/breaking/trending
        // and most-read from the last ~36h, most prominent first.
        $usCatIds = Category::whereIn('slug', ['politics', 'us-news', 'world'])->pluck('id');
        $topQuery = fn ($since) => Post::published()->whereNotNull('source_url')
            ->whereIn('category_id', $usCatIds)
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->orderByDesc('is_featured')->orderByDesc('is_breaking')->orderByDesc('is_trending')
            ->orderByDesc('views')->latest('published_at')
            ->take($count * 3)->get(['title', 'excerpt']);

        $topics = $topQuery(now()->subHours(36));
        if ($topics->count() < $count) {
            $topics = $topQuery(null); // quiet day — fall back to the latest overall
        }

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

            // Original AI illustration for the opinion piece.
            $image = null;
            try {
                $image = $images->generate(
                    'Editorial conceptual illustration for an opinion column titled: '
                    . $op['title'] . '. Tasteful, photorealistic, no text, no logos, no watermarks.'
                );
            } catch (\Throwable $e) {
                // Leave imageless; the backfill job will heal it later.
            }

            Post::create([
                'title' => $op['title'],
                'slug' => $this->uniqueSlug($op['title']),
                'excerpt' => $op['excerpt'],
                'body' => $op['body'],
                'category_id' => $opinion->id,
                'author_id' => $author?->id,
                'featured_image' => $image,
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
        Write a SUBSTANTIAL, original opinion column (550-750 words) responding to the news topic below.
        Rules:
        - Take a clear, thoughtful editorial stance — this is opinion, clearly labeled as such.
        - Structure it like a real column: an engaging opening that stakes out the issue; the strongest
          arguments on each side considered fairly; your reasoned position with why it holds up; and a
          closing paragraph that invites readers to weigh in.
        - Do NOT invent facts, quotes, statistics, dates, or names. Reason from general, widely-understood
          context tied to the topic; keep specific claims general.
        - Be respectful and civil. No hate, personal attacks, defamation, or calls to action against anyone.
        - Write ONLY the column. NEVER include meta-commentary, SEO notes, keywords, "as an AI", or any
          reference to "the summary", "the provided text", or missing information.
        - Headline: an engaging, bold-but-fair stance or question (<= 14 words).
        - Body: 6-8 paragraphs wrapped in <p></p> tags.
        - Excerpt: one sentence teasing the debate.
        SYS;
        if (filled($custom)) {
            $system .= "\n\nHouse style (follow closely):\n" . $custom;
        }

        try {
            $r = Http::withToken(trim($key))->timeout(90)
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
            if (! is_array($data) || blank($data['title'] ?? null)) {
                return null;
            }

            $body = \App\Support\ArticleSanitizer::clean(trim($data['body'] ?? ''));

            // Quality floor: reject anything that came back thin.
            if (str_word_count(strip_tags($body)) < 400) {
                return null;
            }

            return [
                'title' => Str::limit(trim($data['title']), 200, ''),
                'excerpt' => \App\Support\ArticleSanitizer::cleanText(Str::limit(trim($data['excerpt'] ?? ''), 480, '')),
                'body' => $body,
            ];
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
