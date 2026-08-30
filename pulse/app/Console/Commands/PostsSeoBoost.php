<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\SeoBooster;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PostsSeoBoost extends Command
{
    protected $signature = 'posts:seo-boost
        {--target=80 : Only touch posts scoring below this}
        {--limit=25 : Max posts to process this run}
        {--order=score : score|oldest|views — which posts first}
        {--sleep=0 : Seconds to pause between posts}
        {--dry : List what would be processed, write nothing}';

    protected $description = 'Raise on-page SEO of low-scoring posts to the target via SeoBooster: concise title-derived focus keyword, proper meta, H2 subheadings, keyword in the intro, readability, validated links — then re-score. Facts and titles are never changed.';

    public function handle(SeoBooster $booster): int
    {
        $target = (int) $this->option('target');
        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dry = (bool) $this->option('dry');

        if (blank(Setting::get('openai_key', config('services.openai.key')))) {
            $this->error('No OpenAI key configured — aborting.');
            return self::FAILURE;
        }

        $q = Post::published()->where(function ($w) use ($target) {
            $w->whereNull('seo_score')->orWhere('seo_score', '<', $target);
        });
        $q = match ($this->option('order')) {
            'oldest' => $q->orderBy('published_at'),
            'views' => $q->orderByDesc('views'),
            default => $q->orderBy('seo_score'),
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

            if ($dry) {
                $this->line("  ~ {$label} — would boost (currently {$old})");
                continue;
            }

            try {
                $new = $booster->boost($post);
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
}
