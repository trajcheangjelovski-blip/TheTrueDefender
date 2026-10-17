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

#[Signature('stories:generate {--count=1 : How many Story of Hope features to create} {--days=21 : Skip themes that already got a feature within this many days}')]
#[Description('Generate uplifting Story of Hope human-interest features grounded in the themes of recent US news (named author)')]
class StoriesGenerate extends Command
{
    /** Words that carry no topic signal when comparing story themes. */
    private const STOP = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'as', 'at', 'by', 'is', 'are',
        'was', 'from', 'over', 'after', 'into', 'about', 'his', 'her', 'its', 'their', 'new', 'says', 'said',
        'what', 'why', 'how', 'who', 'more', 'than', 'this', 'that', 'must', 'raises', 'questions', 'hard',
        'real', 'debate', 'needs', 'trump', 'biden', 'america', 'americans', 'us', 'u.s.', 'news', 'hope',
    ];

    public function handle(ImageService $images): int
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured.');

            return self::FAILURE;
        }

        $category = Category::where('slug', 'story-of-hope')->first();
        if (! $category) {
            $this->error('Story of Hope category not found.');

            return self::FAILURE;
        }

        // Byline the journalist who covers the human-interest beat (E-E-A-T); fall
        // back to any public author, then an admin.
        $authorId = \App\Support\Bylines::forCategorySlug('story-of-hope');
        $author = ($authorId ? User::find($authorId) : null)
            ?? User::authors()->orderBy('id')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
            ?? User::first();
        $count = max(1, (int) $this->option('count'));

        // Ground each feature in the themes of REAL stories the site has covered
        // (last ~36h, most prominent first) so it reflects the moment rather than
        // being generic — the hopeful human-interest angle on what's in the news.
        $themeCatIds = Category::whereIn('slug', ['politics', 'us-news', 'world', 'story-of-hope'])->pluck('id');
        $topQuery = fn ($since) => Post::published()->whereNotNull('source_url')
            ->whereIn('category_id', $themeCatIds)
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->orderByDesc('is_featured')->orderByDesc('is_breaking')->orderByDesc('is_trending')
            ->orderByDesc('views')->latest('published_at')
            ->take($count * 4)->get(['title', 'excerpt']);

        $themes = $topQuery(now()->subHours(36));
        if ($themes->count() < $count) {
            $themes = $topQuery(null); // quiet day — fall back to the latest overall
        }

        // One feature per theme: skip any theme that shares its key words with a
        // Story of Hope published recently, so the section doesn't repeat itself.
        // Checked BEFORE the AI call, so a repeat theme costs nothing.
        $recent = Post::where('category_id', $category->id)
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->get(['title', 'excerpt'])
            ->map(fn ($p) => $this->tokens($p->title . ' ' . $p->excerpt))
            ->all();

        // Story of Hope features count toward the shared daily publish ceiling.
        if (\App\Support\DailyCap::reached()) {
            $this->info('Daily publish cap already reached — no Story of Hope today.');

            return self::SUCCESS;
        }

        $made = 0;
        foreach ($themes as $theme) {
            if ($made >= $count || \App\Support\DailyCap::reached()) {
                break;
            }
            $themeTokens = $this->tokens($theme->title);
            if ($this->alreadyCovered($themeTokens, $recent)) {
                $this->line('  skip (recent feature on this theme): ' . Str::limit($theme->title, 60));
                continue;
            }

            $story = $this->write($theme->title, (string) $theme->excerpt, $key);
            if (! $story) {
                continue;
            }
            $recent[] = $this->tokens($story['title'] . ' ' . $theme->title); // no repeats within this run either

            // Original, warm AI illustration for the feature.
            $image = null;
            try {
                $image = $images->generate(
                    'Warm, uplifting editorial illustration for a human-interest feature titled: '
                    . $story['title'] . '. Hopeful, photorealistic, tasteful, no text, no logos, no watermarks.'
                );
            } catch (\Throwable $e) {
                // Leave imageless; the backfill job will heal it later.
            }

            Post::create([
                'title' => $story['title'],
                'slug' => $this->uniqueSlug($story['title']),
                'excerpt' => $story['excerpt'],
                'body' => $story['body'],
                'category_id' => $category->id,
                'author_id' => $author?->id,
                'featured_image' => $image,
                'image_icon' => '🕊️',
                'status' => 'published',
                'published_at' => now(),
                'allow_comments' => true,
                // Suppress push/social fan-out for batch-generated features.
                'push_notified_at' => now(),
                'social_posted_at' => now(),
            ]);
            $this->info('Created Story of Hope: ' . $story['title']);
            $made++;
        }

        $this->info("Generated {$made} Story of Hope feature(s).");

        return self::SUCCESS;
    }

    /** @return array{title:string,excerpt:string,body:string}|null */
    private function write(string $themeTitle, string $themeExcerpt, string $key): ?array
    {
        set_time_limit(120);
        $site = config('app.name', 'TheTrueDefender');
        $custom = Setting::get('ai_instructions');

        $system = <<<SYS
        You are a human-interest feature writer for "{$site}", an independent US news outlet.
        Write a SUBSTANTIAL, original, uplifting "Story of Hope" feature (550-750 words) inspired by the
        news theme below — the hopeful human dimension of what's happening: resilience, community, generosity,
        kindness, recovery, or genuine human progress.
        Rules:
        - Find the authentically hopeful angle connected to the theme: people helping people, communities
          coming together, quiet acts of decency, and well-founded reasons for optimism.
        - This is a reflective human-interest feature, NOT breaking news. Do NOT invent specific events,
          people, names, quotes, statistics, dates, places, or organizations, and do NOT present it as a
          report of a particular incident. Reason from general, widely-understood context; keep every
          specific claim general.
        - Warm, sincere, grounded tone — hopeful without being saccharine. Strictly non-partisan and
          non-political. If the theme is painful, center the constructive human response, not the tragedy.
        - Be respectful and civil. No hate, no calls to action against anyone.
        - Write ONLY the feature. NEVER include meta-commentary, SEO notes, keywords, "as an AI", or any
          reference to "the summary", "the provided text", or missing information.
        - Headline: warm and inviting, <= 14 words, not a fabricated specific event.
        - Body: 6-8 paragraphs wrapped in <p></p> tags.
        - Excerpt: one uplifting sentence that draws the reader in.
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
                        ['role' => 'user', 'content' => "NEWS THEME: {$themeTitle}\n\nCONTEXT: {$themeExcerpt}"],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'story_of_hope',
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
            \Illuminate\Support\Facades\Log::warning('Story of Hope generation failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Significant lowercase words of a title/excerpt (names, places, subjects). */
    private function tokens(string $text): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 3 && ! ctype_digit($t) && ! in_array($t, self::STOP, true))
            ->unique()->values()->all();
    }

    /**
     * Does this theme share enough key words with a recent feature to count as
     * the same story? 3+ shared words, or 2+ covering most of the theme.
     *
     * @param array<int,string> $theme
     * @param array<int,array<int,string>> $recent
     */
    private function alreadyCovered(array $theme, array $recent): bool
    {
        if (! $theme) {
            return false;
        }
        foreach ($recent as $seen) {
            $overlap = count(array_intersect($theme, $seen));
            if ($overlap >= 3 || ($overlap >= 2 && $overlap >= 0.6 * count($theme))) {
                return true;
            }
        }

        return false;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'story-of-hope';
        $slug = $base;
        $i = 2;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
