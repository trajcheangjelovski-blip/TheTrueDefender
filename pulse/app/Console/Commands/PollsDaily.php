<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Poll;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PollsDaily extends Command
{
    protected $signature = 'polls:daily {--force : Generate even if one was already made today}';

    protected $description = 'Generate a fresh reader poll from the day\'s headlines and make it the active poll.';

    public function handle(): int
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured.');
            return self::FAILURE;
        }

        if (! $this->option('force') && Setting::get('daily_poll_at') === now()->toDateString()) {
            $this->info('A daily poll was already created today.');
            return self::SUCCESS;
        }

        $ids = Category::whereIn('slug', ['politics', 'us-news', 'world'])->pluck('id');
        $headlines = Post::published()->whereIn('category_id', $ids)
            ->latest('published_at')->take(12)->pluck('title')->implode("\n- ");

        $data = $this->generate($key, $headlines);
        if (! $data) {
            $this->error('Poll generation failed.');
            return self::FAILURE;
        }

        // Only the newest active poll shows on the homepage — retire the rest.
        Poll::where('is_active', true)->update(['is_active' => false]);

        $poll = Poll::create(['question' => $data['question'], 'is_active' => true]);
        foreach (array_values($data['options']) as $i => $label) {
            $poll->options()->create(['label' => Str::limit($label, 60, ''), 'sort_order' => $i]);
        }

        Setting::put('daily_poll_at', now()->toDateString());
        $this->info('New daily poll: ' . $data['question']);

        return self::SUCCESS;
    }

    /** @return array{question:string,options:array<int,string>}|null */
    private function generate(string $key, string $headlines): ?array
    {
        try {
            set_time_limit(60);
            $res = Http::withToken(trim($key))->timeout(45)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'You write ONE short, engaging reader-poll question for an American news site, '
                            . 'inspired by today\'s headlines. Neutral, civil, non-partisan wording that a broad '
                            . 'audience can answer. No hate, no defamation, no loaded premises. Provide 3-4 short '
                            . 'answer options. Return only the question and options.'],
                        ['role' => 'user', 'content' => "Today's headlines:\n- " . $headlines],
                    ],
                    'response_format' => ['type' => 'json_schema', 'json_schema' => [
                        'name' => 'poll', 'strict' => true, 'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 4],
                            ],
                            'required' => ['question', 'options'],
                            'additionalProperties' => false,
                        ],
                    ]],
                ]);

            if (! $res->ok()) {
                return null;
            }
            $d = json_decode(data_get($res->json(), 'choices.0.message.content', ''), true);

            return (isset($d['question'], $d['options']) && count($d['options']) >= 2) ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
