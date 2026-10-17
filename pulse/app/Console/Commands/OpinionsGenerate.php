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

#[Signature('opinions:generate {--count=1 : How many opinion columns to create} {--days=21 : Skip stories that already got an opinion column within this many days}')]
#[Description('Generate opinion columns grounded in recent US news the site has covered (one per story, named author)')]
class OpinionsGenerate extends Command
{
    /** Words that carry no topic signal when comparing story titles. */
    private const STOP = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'as', 'at', 'by', 'is', 'are',
        'was', 'from', 'over', 'after', 'into', 'about', 'his', 'her', 'its', 'their', 'new', 'says', 'said',
        'what', 'why', 'how', 'who', 'more', 'than', 'this', 'that', 'must', 'raises', 'questions', 'hard',
        'real', 'debate', 'needs', 'trump', 'biden', 'america', 'americans', 'us', 'u.s.', 'news',
    ];
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

        // Byline the journalist who covers opinion/politics (E-E-A-T); fall back
        // to any public author, then an admin.
        $authorId = \App\Support\Bylines::forCategorySlug('opinion');
        $author = ($authorId ? User::find($authorId) : null)
            ?? User::authors()->orderBy('id')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
            ?? User::first();
        $count = max(1, (int) $this->option('count'));

        // Base opinions on the TOP stories of the day: featured/breaking/trending
        // and most-read from the last ~36h, most prominent first.
        $usCatIds = Category::whereIn('slug', ['politics', 'us-news', 'world'])->pluck('id');
        $topQuery = fn ($since) => Post::published()->whereNotNull('source_url')
            ->whereIn('category_id', $usCatIds)
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->orderByDesc('is_featured')->orderByDesc('is_breaking')->orderByDesc('is_trending')
            ->orderByDesc('views')->latest('published_at')
            ->take($count * 3)->get(['id', 'slug', 'title', 'excerpt', 'body']);

        $topics = $topQuery(now()->subHours(36));
        if ($topics->count() < $count) {
            $topics = $topQuery(null); // quiet day — fall back to the latest overall
        }

        // One column per story: skip any news topic that shares its key names /
        // places with an opinion published recently (this is what produced three
        // near-identical columns on the same ambush). Checked BEFORE the AI call,
        // so a repeat topic costs nothing.
        $recent = Post::where('category_id', $opinion->id)
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->get(['title', 'excerpt'])
            ->map(fn ($p) => $this->tokens($p->title . ' ' . $p->excerpt))
            ->all();

        // Opinion columns count toward the shared daily publish ceiling and use the
        // Opinion section's reserved slot (DailyCap::categoryReserves).
        if (\App\Support\DailyCap::remainingFor('opinion') <= 0) {
            $this->info('Daily publish cap already reached — no opinion column today.');

            return self::SUCCESS;
        }

        $made = 0;
        foreach ($topics as $topic) {
            if ($made >= $count || \App\Support\DailyCap::remainingFor('opinion') <= 0) {
                break;
            }
            $topicTokens = $this->tokens($topic->title);
            if ($this->alreadyCovered($topicTokens, $recent)) {
                $this->line('  skip (already has a recent column): ' . Str::limit($topic->title, 60));
                continue;
            }

            // Same originality bar as news (no headline echo, repeated/reused
            // sentences, stock phrases), then an INDEPENDENT fact-check of the
            // column's factual claims against the article it responds to.
            // One fresh attempt if the first fails.
            $op = null;
            $fc = null;
            $ai = new \App\Services\AiClient;
            $evidence = [['name' => config('app.name'), 'url' => route('post.show', $topic), 'text' => strip_tags((string) $topic->body)]];
            for ($try = 1; $try <= 2 && ! $op; $try++) {
                $draft = $this->write($topic->title, Str::limit(trim(strip_tags(str_replace('</p>', "</p>\n", (string) $topic->body))), 6000, ''), $key);
                $fails = $draft ? \App\Support\PublishGate::originality(new Post(['title' => $draft['title'], 'body' => $draft['body']])) : ['empty' => 'writer returned nothing'];
                if (! $fails) {
                    $fc = app(\App\Services\FactChecker::class)->check($ai, $draft['title'], $draft['body'], $evidence, null, opinion: true);
                    if ($fc['verdict'] !== 'ready') {
                        $fails = ['fact-check' => "fact-check {$fc['verdict']}: " . \Illuminate\Support\Str::limit(\App\Services\FactChecker::feedback($fc), 200)];
                    }
                }
                if ($fails) {
                    $this->line("  attempt {$try} rejected (" . implode('; ', array_values($fails)) . '): ' . Str::limit($topic->title, 50));
                } else {
                    $op = $draft;
                }
            }
            if (! $op) {
                continue;
            }
            $recent[] = $this->tokens($op['title'] . ' ' . $topic->title); // no repeats within this run either

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
                'story_type' => 'opinion',
                'policy_version' => \App\Support\EditorialPolicy::version(),
                'fact_check' => $fc,
                'review_status' => 'ready',
                'ai_usage' => $ai->usage(),
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
        $angle = $this->nextAngle();
        $avoid = $this->recentColumnLines();
        $banned = implode(', ', array_map(fn ($p) => '"' . $p . '"', \App\Support\PublishGate::STOCK_PHRASES));

        $system = <<<SYS
        You are an opinion columnist for "{$site}", an independent US news outlet.
        Write a SUBSTANTIAL, original opinion column (550-750 words) responding to the news topic below.
        Rules:
        - Take a clear, thoughtful editorial stance — this is opinion, clearly labeled as such.
        - This column's argument: {$angle}
        - Open with your sharpest specific point about this story — never restate or paraphrase the headline.
        - Weigh the strongest opposing argument fairly, then make your case with this story's specifics.
        - End on your own concrete point about THIS story — never a moral, a lesson, a slogan, an invitation
          for readers to "weigh in", or advice to readers, families or Americans.
        - Never repeat a sentence or phrase, and never use these phrases: {$banned}.
        {$avoid}
        - Every factual claim must come from the CONTEXT article below. Do NOT invent facts, quotes,
          statistics, dates, or names; widely-known general background may be stated only in general terms.
        - Be respectful and civil. No hate, personal attacks, defamation, or calls to action against anyone.
        - Write ONLY the column. NEVER include meta-commentary, SEO notes, keywords, "as an AI", or any
          reference to "the summary", "the provided text", or missing information.
        - Headline: an engaging, bold-but-fair stance or question (<= 14 words).
        - Body: 6-8 paragraphs wrapped in <p></p> tags.
        - Excerpt: one sentence teasing the debate.
        SYS;
        // The permanent editorial policy (+ admin house-voice notes) governs columns
        // too; the column-specific rules above add to it.
        $system = \App\Support\EditorialPolicy::forPrompt() . "\n\n=== THIS TASK: AN OPINION COLUMN ===\n" . $system;

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

    /** Column arguments, rotated so consecutive columns never take the same shape. */
    private const ANGLES = [
        'ARGUE FOR one specific policy, decision or action this story calls for, and defend it against the best objection.',
        'ARGUE AGAINST a decision, policy or claim at the center of this story, and say what should happen instead.',
        'THE OVERLOOKED POINT — argue that coverage of this story misses something important, and show why it changes the picture.',
        'THE PRECEDENT — argue what this story sets in motion beyond this one case, using only the facts and widely-known context.',
        'THE WEIGHING — set the two strongest positions side by side, then come down firmly on one.',
    ];

    private function nextAngle(): string
    {
        $i = ((int) Setting::get('opinion_angle_index', -1) + 1) % count(self::ANGLES);
        Setting::put('opinion_angle_index', (string) $i);

        return self::ANGLES[$i];
    }

    /** How recent opinion columns opened and ended, so a new one never reuses them. */
    private function recentColumnLines(): string
    {
        $opinionId = \App\Models\Category::where('slug', 'opinion')->value('id');
        $lines = Post::where('category_id', $opinionId)->latest('published_at')->take(10)->pluck('body')
            ->flatMap(function ($body) {
                $s = preg_split('/(?<=[.!?])\s+/', trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</p>', ' </p>', (string) $body)))))) ?: [];

                return array_filter([$s[0] ?? null, count($s) > 1 ? end($s) : null]);
            })
            ->filter(fn ($l) => mb_strlen($l) >= 25)
            ->map(fn ($l) => '  • ' . Str::limit($l, 160))
            ->unique()->values();

        return $lines->isEmpty() ? '' : "- Do not reuse the wording or pattern of how these recent columns opened and ended:\n"
            . $lines->implode("\n") . "\n";
    }

    /** Significant lowercase words of a title/excerpt (names, places, subjects). */
    private function tokens(string $text): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 3 && ! ctype_digit($t) && ! in_array($t, self::STOP, true))
            ->unique()->values()->all();
    }

    /**
     * Does this news topic share enough key words with a recent opinion column to
     * count as the same story? 3+ shared words, or 2+ covering most of the topic.
     *
     * @param array<int,string> $topic
     * @param array<int,array<int,string>> $recent
     */
    private function alreadyCovered(array $topic, array $recent): bool
    {
        if (! $topic) {
            return false;
        }
        foreach ($recent as $seen) {
            $overlap = count(array_intersect($topic, $seen));
            if ($overlap >= 3 || ($overlap >= 2 && $overlap >= 0.6 * count($topic))) {
                return true;
            }
        }

        return false;
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
