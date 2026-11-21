<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ImageService;
use App\Services\SeoBooster;
use App\Support\AutoPublication;
use App\Support\Publisher;
use App\Support\PublishGate;
use Illuminate\Console\Command;

class PostsPromoteQueued extends Command
{
    protected $signature = 'posts:promote-queued {--per-run=20 : Processing batch size, not a publication quota} {--dry}';

    protected $description = 'Publish eligible verified drafts immediately; retain every held draft';

    public function handle(ImageService $images, SeoBooster $booster): int
    {
        $count = 0;
        foreach (Post::where('status', 'draft')->whereNotNull('queued_at')->where('review_status', 'ready')
            ->orderByDesc('is_breaking')->orderBy('queued_at')->cursor() as $post) {
            if (! AutoPublication::allowed($post) || PublishGate::failures($post)) {
                continue;
            }
            if ($this->option('dry')) {
                $this->line("Eligible #{$post->id}: {$post->title}");
            } else {
                Publisher::publish($post, $images, $booster);
                if ($post->fresh()->status !== 'published') {
                    continue;
                }
            }
            if (++$count >= max(1, (int) $this->option('per-run'))) {
                break;
            }
        }
        $this->info("Checked {$count} eligible article(s). Held drafts retained.");

        return self::SUCCESS;
    }
}
