<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\TagGenerator;
use Illuminate\Console\Command;

class TagsBackfill extends Command
{
    protected $signature = 'tags:backfill {--all : Re-tag every post, even ones that already have topics} {--limit=0 : Max posts to process (0 = no cap)}';

    protected $description = 'AI-generate evergreen topic tags for existing published posts (builds the /topic hub pages)';

    public function handle(TagGenerator $tagger): int
    {
        $query = Post::query()->where('status', 'published');

        if (! $this->option('all')) {
            $query->whereDoesntHave('tags');
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $posts = $query->latest('published_at')->get(['id', 'title', 'body']);
        $total = $posts->count();

        if ($total === 0) {
            $this->info('Nothing to tag — all posts already have topics. Use --all to re-tag.');

            return self::SUCCESS;
        }

        $this->info("Tagging {$total} post(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $tagged = 0;
        foreach ($posts as $post) {
            $names = $tagger->suggest($post->title, $post->body);
            if (! empty($names)) {
                $post->syncTagsFromNames($names);
                $tagged++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Tagged {$tagged} of {$total} post(s).");

        return self::SUCCESS;
    }
}
