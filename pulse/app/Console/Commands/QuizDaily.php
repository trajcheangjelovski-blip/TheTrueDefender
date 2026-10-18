<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\QuizAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class QuizDaily extends Command
{
    protected $signature = 'quiz:daily {--force : Regenerate even if a valid quiz exists (still requires enough recent published stories)}';

    protected $description = 'Build a daily multiple-choice news quiz from recently published stories. Makes no API request unless enough recent published stories exist.';

    public function handle(): int
    {
        // Free checks first. Nothing below this block may reach a paid API without a real
        // story packet, and --force does not bypass it.
        $posts = QuizAvailability::eligiblePosts();
        if ($posts->count() < QuizAvailability::MIN_STORIES) {
            $this->info('Not enough recent published stories (' . $posts->count() . ' of ' . QuizAvailability::MIN_STORIES
                . ' needed) - no quiz built, no API request made.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && QuizAvailability::available()) {
            $this->info('A valid quiz already exists for today.');

            return self::SUCCESS;
        }

        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured.');

            return self::FAILURE;
        }

        $stories = $posts->map(fn ($p) => '- ' . $p->title . ' — ' . Str::limit(strip_tags((string) $p->excerpt), 140))
            ->implode("\n");
        if (trim($stories) === '') {
            return self::SUCCESS;
        }

        $questions = $this->generate($key, $stories);
        if (! $questions) {
            $this->error('Quiz generation failed.');

            return self::FAILURE;
        }

        Setting::put('daily_quiz', json_encode([
            'version' => QuizAvailability::VERSION,
            'date' => QuizAvailability::today(),
            'generated_at' => now()->toIso8601String(),
            'post_ids' => $posts->pluck('id')->all(),   // the stories this quiz is supported by
            'questions' => $questions,
        ]));

        $this->info('Built daily quiz with ' . count($questions) . ' questions from ' . $posts->count() . ' stories.');

        return self::SUCCESS;
    }

    private function generate(string $key, string $stories): ?array
    {
        try {
            set_time_limit(90);
            $res = Http::withToken(trim($key))->timeout(60)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'Create a fun 5-question multiple-choice quiz that tests readers on THIS WEEK\'s news, '
                            . 'using only the stories provided. Each question has exactly 4 options and one correct '
                            . 'answer (index 0-3) that is clearly supported by the stories. Add a one-sentence '
                            . 'explanation. Keep it factual, neutral, and answerable from the stories — do not invent '
                            . 'facts beyond them. No meta-commentary.'],
                        ['role' => 'user', 'content' => "Recent stories:\n" . $stories],
                    ],
                    'response_format' => ['type' => 'json_schema', 'json_schema' => [
                        'name' => 'quiz', 'strict' => true, 'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'questions' => [
                                    'type' => 'array', 'minItems' => 5, 'maxItems' => 5,
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'question' => ['type' => 'string'],
                                            'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 4, 'maxItems' => 4],
                                            'answer' => ['type' => 'integer'],
                                            'explain' => ['type' => 'string'],
                                        ],
                                        'required' => ['question', 'options', 'answer', 'explain'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['questions'],
                            'additionalProperties' => false,
                        ],
                    ]],
                ]);

            if (! $res->ok()) {
                return null;
            }
            $d = json_decode(data_get($res->json(), 'choices.0.message.content', ''), true);
            $q = $d['questions'] ?? null;

            return (is_array($q) && count($q) >= 3) ? $q : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
