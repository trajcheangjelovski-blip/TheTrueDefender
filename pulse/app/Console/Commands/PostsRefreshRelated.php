<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ArticleUpdateService;
use App\Services\EditorialPipeline;
use App\Services\FeedRelatedResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PostsRefreshRelated extends Command
{
    protected $signature = 'posts:refresh-related {--limit=2} {--window-hours=72} {--dry}';

    protected $description = 'Find material new evidence and propose additive article revisions';

    public function handle(FeedRelatedResearcher $feeds, EditorialPipeline $pipeline, ArticleUpdateService $updates): int
    {
        $count = 0;
        foreach (Post::where('status', 'published')->whereNotNull('source_url')
            ->where('published_at', '>=', now()->subHours(max(1, (int) $this->option('window-hours'))))
            ->orderBy('published_at')->cursor() as $post) {
            if ($count >= max(1, (int) $this->option('limit'))) {
                break;
            }
            if ($post->revisions()->whereIn('status', ['pending_review', 'needs_research', 'needs_rewrite'])->exists()) {
                continue;
            }
            if (! $this->option('dry') && ! Cache::add('update-scan:'.$post->id, true, now()->addMinutes(30))) {
                continue;
            }
            $newer = $feeds->research($post->title, [], 4, $post->content_updated_at ?? $post->published_at);
            if (! $newer) {
                continue;
            }
            $count++;
            $fingerprint = hash('sha256', json_encode(array_map(fn ($s) => [$s['url'], $s['published_at'], $s['text']], $newer)));
            if ($this->option('dry')) {
                $this->line("#{$post->id}: ".count($newer).' candidate sources');

                continue;
            }
            $lock = Cache::lock('article-update:'.$post->id, 7200);
            if (! $lock->get()) {
                continue;
            }
            try {
                $check = DB::table('newsroom_update_checks')->where('post_id', $post->id)->where('fingerprint', $fingerprint)->first();
                if ($check && $check->status !== 'retry'
                    && ! ($check->status === 'pending' && Carbon::parse($check->updated_at)->lt(now()->subHours(2)))) {
                    continue;
                }
                $evidence = $check ? json_decode($check->evidence, true) : $pipeline->recordedEvidence($post, $newer);
                if (! $check) {
                    DB::table('newsroom_update_checks')->insert(['post_id' => $post->id, 'fingerprint' => $fingerprint,
                        'status' => 'pending', 'evidence' => json_encode($evidence), 'created_at' => now(), 'updated_at' => now()]);
                }
                $revision = $updates->propose($post, $evidence);
                DB::table('newsroom_update_checks')->where('post_id', $post->id)->where('fingerprint', $fingerprint)
                    ->update(['status' => $revision ? 'proposed' : 'no_change', 'error' => null, 'updated_at' => now()]);
            } catch (\Throwable $e) {
                DB::table('newsroom_update_checks')->where('post_id', $post->id)->where('fingerprint', $fingerprint)
                    ->update(['status' => 'retry', 'error' => class_basename($e), 'updated_at' => now()]);
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
