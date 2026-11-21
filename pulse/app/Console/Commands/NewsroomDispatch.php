<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNewsArticle;
use App\Models\Post;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NewsroomDispatch extends Command
{
    protected $signature = 'newsroom:dispatch';

    protected $description = 'Recover and enqueue durable newsroom work, bounded by worker capacity';

    public function handle(): int
    {
        $room = max(0, 100 - DB::table('jobs')->whereIn('queue', ['news', 'news-urgent'])->count());
        foreach (Post::where('status', 'draft')->whereNotNull('queued_at')
            ->where(fn ($q) => $q->whereNull('review_status')->orWhereNotIn('review_status', ['ready', 'rejected']))
            ->orderByDesc('is_breaking')->orderBy('queued_at')->cursor() as $post) {
            if ($room <= 0) {
                break;
            }
            $job = new ProcessNewsArticle($post->id, (bool) $post->is_breaking);
            $key = (new UniqueLock(Cache::store()))->getKey($job);
            if (Cache::has($key)) {
                continue;
            }
            ProcessNewsArticle::dispatch($post->id, (bool) $post->is_breaking);
            $room--;
        }

        return self::SUCCESS;
    }
}
