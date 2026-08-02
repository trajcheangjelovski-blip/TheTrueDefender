<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ContentEnricher;
use Illuminate\Console\Command;

class ContentEnrich extends Command
{
    protected $signature = 'content:enrich {--all : Re-enrich every post, even ones that already have takeaways/FAQ} {--limit=0 : Max posts to process (0 = no cap)}';

    protected $description = 'AI-generate Key Takeaways + FAQ for existing published posts (summary box, FAQ block, FAQPage schema)';

    public function handle(ContentEnricher $enricher): int
    {
        $query = Post::query()->where('status', 'published');

        if (! $this->option('all')) {
            // Only posts still missing both.
            $query->where(fn ($q) => $q->whereNull('takeaways')->orWhereNull('faqs'));
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $posts = $query->latest('published_at')->get(['id', 'title', 'body', 'takeaways', 'faqs']);
        $total = $posts->count();

        if ($total === 0) {
            $this->info('Nothing to enrich — all posts already have takeaways + FAQ. Use --all to regenerate.');

            return self::SUCCESS;
        }

        $this->info("Enriching {$total} post(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        foreach ($posts as $post) {
            $result = $enricher->enrich($post->title, $post->body);
            $update = [];
            if (! empty($result['takeaways'])) {
                $update['takeaways'] = $result['takeaways'];
            }
            if (! empty($result['faqs'])) {
                $update['faqs'] = $result['faqs'];
            }
            if ($update) {
                $post->forceFill($update)->saveQuietly();
                $done++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Enriched {$done} of {$total} post(s).");

        return self::SUCCESS;
    }
}
