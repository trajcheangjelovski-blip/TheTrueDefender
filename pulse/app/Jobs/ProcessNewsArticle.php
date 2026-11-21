<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\Setting;
use App\Services\ImageService;
use App\Services\SeoBooster;
use App\Services\StorySynthesizer;
use App\Support\AiContext;
use App\Support\AutoPublication;
use App\Support\Publisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNewsArticle implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $timeout = 5400;

    public int $uniqueFor = 0; // held until completion/failure, including offline retries

    public function __construct(public int $postId, bool $urgent = false)
    {
        $this->onConnection('database')->onQueue($urgent ? 'news-urgent' : 'news');
    }

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function handle(StorySynthesizer $synth, ImageService $images, SeoBooster $booster): void
    {
        $post = Post::find($this->postId);
        if (! $post || $post->status !== 'draft' || ! $post->queued_at || $post->review_status === 'rejected') {
            return;
        }
        AiContext::$lane = $post->is_breaking ? 'urgent' : 'news';
        try {
            if ($post->review_status !== 'ready') {
                $why = $synth->synthesize($post, max(3, (int) Setting::get('min_sources_to_publish', 3)));
                $post->refresh();
                if ($why || $post->review_status !== 'ready') {
                    if ($post->review_status !== 'rejected') {
                        $this->release(900);
                    }

                    return;
                }
            }
            if (AutoPublication::allowed($post)) {
                Publisher::publish($post, $images, $booster);
            }
        } finally {
            AiContext::$lane = 'news';
        }
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }
}
