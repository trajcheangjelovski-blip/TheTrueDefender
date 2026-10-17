<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRevision;
use App\Services\EditorialPipeline;
use App\Support\EditorialPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Re-write PUBLISHED articles under the current editorial policy as REVISION
 * DRAFTS for editorial approval. The live articles are never modified here —
 * an editor applies a revision in the admin (or with posts:revision apply).
 *
 * For each article: research its sources and independent coverage, fact-check
 * the published version, rewrite it as of its original publication date,
 * fact-check the rewrite (one fix pass), and save a PostRevision with the
 * original snapshot, sources, findings, corrections and AI usage.
 *
 * Safe to re-run: articles that already have a revision under the current
 * policy version are skipped (resumable after an interruption, no duplicates),
 * and a per-article lock prevents two runs from revising the same article.
 *
 *   php artisan posts:revise-live --dry          # list what would be revised
 *   php artisan posts:revise-live --ids=1,2,3    # specific articles
 *   php artisan posts:revise-live --limit=10     # the next 10 not yet revised
 *   php artisan posts:revise-live                # all remaining published articles
 */
class PostsReviseLive extends Command
{
    protected $signature = 'posts:revise-live
        {--ids= : Comma-separated post IDs (default: all published articles)}
        {--limit=0 : Max articles this run (0 = no limit)}
        {--force : Revise again even if a revision exists for the current policy}
        {--dry : List what would be revised, change nothing}';

    protected $description = 'Rewrite published articles under the editorial policy as revision drafts for editorial approval (never changes live articles).';

    public function handle(EditorialPipeline $pipeline): int
    {
        $version = EditorialPolicy::version();
        $query = Post::where('status', 'published')->whereNotNull('source_url')->orderBy('published_at');
        if (filled($ids = $this->option('ids'))) {
            $query->whereIn('id', array_map('intval', explode(',', $ids)));
        }
        $posts = $query->get();

        if (! $this->option('force')) {
            $done = PostRevision::where('policy_version', $version)->where('status', '!=', 'rolled_back')->pluck('post_id')->all();
            $posts = $posts->reject(fn ($p) => in_array($p->id, $done, true))->values();
        }
        if (($limit = (int) $this->option('limit')) > 0) {
            $posts = $posts->take($limit);
        }

        $this->info(($this->option('dry') ? '[DRY] ' : '') . "Policy v{$version}: {$posts->count()} article(s) to revise.");
        if ($this->option('dry') || $posts->isEmpty()) {
            foreach ($posts as $p) {
                $this->line("  #{$p->id}  " . Str::limit($p->title, 70));
            }

            return self::SUCCESS;
        }

        // Complete backup of every article in this run, before any work.
        $path = 'backups/revise-live-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($path, $posts->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'published_at' => (string) $p->published_at] + PostRevision::snapshotOf($p))->values()->toJson(JSON_PRETTY_PRINT));
        $this->info("Backed up {$posts->count()} article(s) to storage/app/{$path}");

        $tally = [];
        foreach ($posts as $post) {
            $lock = Cache::lock("revise-post:{$post->id}", 1800);
            if (! $lock->get()) {
                $this->line("  · #{$post->id} skipped — another run is revising it");
                continue;
            }
            try {
                $rev = $pipeline->reviseExisting($post);
                $tally[$rev->status] = ($tally[$rev->status] ?? 0) + 1;
                $fc = (array) $rev->fact_check;
                $this->line(sprintf(
                    '  %s #%d → revision #%d [%s] critical=%d corrections=%d calls=%d tokens=%d  %s',
                    $rev->status === 'pending_review' ? '✓' : '·',
                    $post->id, $rev->id, $rev->status, (int) ($fc['critical'] ?? 0),
                    count((array) ($rev->change_log['corrections'] ?? [])),
                    (int) ($rev->ai_usage['calls'] ?? 0),
                    (int) (($rev->ai_usage['prompt_tokens'] ?? 0) + ($rev->ai_usage['completion_tokens'] ?? 0)),
                    Str::limit($post->title, 45),
                ));
            } catch (\Throwable $e) {
                $tally['failed'] = ($tally['failed'] ?? 0) + 1;
                Log::error("posts:revise-live failed for #{$post->id}: " . $e->getMessage());
                $this->warn("  ✗ #{$post->id} failed: " . Str::limit($e->getMessage(), 140));
            } finally {
                $lock->release();
            }
        }

        $this->info('Done — ' . collect($tally)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ') . ". Backup: storage/app/{$path}");
        $this->info('Nothing is live yet: review and apply revisions in Admin → Revisions (or posts:revision).');

        return self::SUCCESS;
    }
}
