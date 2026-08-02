<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StyleLearn extends Command
{
    protected $signature = 'style:learn {--dry : Show the analysis without saving}';

    protected $description = 'Learn hook/style patterns from top-performing posts and update the AI house-hook guide.';

    /** Ignore CTR for posts with fewer impressions than this (too noisy). */
    private const MIN_IMPRESSIONS = 30;

    public function handle(): int
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->error('No OpenAI key configured.');
            return self::FAILURE;
        }

        $posts = Post::where('status', 'published')->get(['id', 'title', 'body', 'views', 'impressions', 'clicks']);

        // Prefer real CTR once enough click data exists; otherwise fall back to views.
        $ctrEligible = $posts->filter(fn ($p) => $p->impressions >= self::MIN_IMPRESSIONS);
        $useCtr = $ctrEligible->count() >= 8 && $ctrEligible->sum('clicks') >= 40;

        if ($useCtr) {
            $scored = $ctrEligible->map(fn ($p) => [$p, $p->clicks / max(1, $p->impressions)]);
            $signal = 'click-through rate';
        } else {
            $scored = $posts->map(fn ($p) => [$p, (float) $p->views]);
            $signal = 'views';
        }

        $ranked = $scored->sortByDesc(fn ($x) => $x[1])->values();
        $top = $ranked->take(15)->map(fn ($x) => $this->line_of($x[0]));
        $weak = $ranked->reverse()->take(8)->map(fn ($x) => $this->line_of($x[0]));

        $this->info("Signal: {$signal}. Top {$top->count()} vs weak {$weak->count()}.");
        if ($this->option('dry')) {
            $this->line("TOP:\n" . $top->implode("\n"));
            return self::SUCCESS;
        }

        $guide = $this->distill($key, $top->implode("\n"), $weak->implode("\n"));
        if (! $guide) {
            $this->error('Could not distill a style guide.');
            return self::FAILURE;
        }

        Setting::put('learned_style_guide', $guide);
        Setting::put('learned_style_at', now()->toDateString());
        Setting::put('learned_style_signal', $signal);

        $this->info('Updated house-hook guide from ' . $signal . '.');
        return self::SUCCESS;
    }

    private function line_of(Post $p): string
    {
        $lead = Str::of(strip_tags($p->body))->squish()->limit(140, '')->value();

        return '• "' . $p->title . '" — ' . $lead;
    }

    private function distill(string $key, string $top, string $weak): ?string
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
                            'You are an editorial coach. You are given headlines + opening lines that performed BEST '
                            . 'on a US news site, and some that performed WORST. Write a concise HOUSE HOOK GUIDE '
                            . '(max ~130 words) of the specific, concrete qualities that make the best ones work — '
                            . 'patterns an AI writer should follow (specificity, stakes, active verbs, names/numbers, '
                            . 'curiosity gap, etc.). End with one short line: what to AVOID, based on the weak ones. '
                            . 'Output only the guidance — no preamble, no headings, no mention of this task.'],
                        ['role' => 'user', 'content' => "BEST PERFORMERS:\n{$top}\n\nWEAK PERFORMERS:\n{$weak}"],
                    ],
                ]);

            if (! $res->ok()) {
                return null;
            }
            $text = trim((string) data_get($res->json(), 'choices.0.message.content', ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
