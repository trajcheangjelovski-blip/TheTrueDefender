<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CLEAN SLATE. Permanently delete EVERY post (all statuses) so the site can
 * restart on the new multi-source model. A full JSON backup is written first.
 *
 * Deleting a post cascades at the database level to its comments, social posts
 * and tag links, and nulls the reference from ingested_items (so the old source
 * items are NOT re-ingested — new coverage only). No OpenAI calls.
 *
 * This is irreversible once applied — hence the required --apply flag and the
 * interactive confirmation. Keep the backup file.
 *
 *   php artisan posts:wipe            # dry run: how many would be deleted
 *   php artisan posts:wipe --apply    # back up, confirm, then delete everything
 */
class PostsWipe extends Command
{
    protected $signature = 'posts:wipe
        {--apply : Actually delete (without this it is a dry run)}
        {--force : Skip the interactive confirmation (for scripted use)}';

    protected $description = 'Clean slate: permanently delete ALL posts (backup written first). Irreversible. No AI calls.';

    public function handle(): int
    {
        $total = Post::query()->count();
        $this->info("Total posts in database: {$total}");

        if ($total === 0) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        // Always write a full backup (even on a dry run) so there is a record.
        $stamp = now()->format('Ymd-His');
        $path = "backups/wipe-all-posts-{$stamp}.json";
        $rows = Post::query()->get()->map(fn (Post $p) => $p->only([
            'id', 'title', 'slug', 'excerpt', 'body', 'takeaways', 'faqs',
            'category_id', 'author_id', 'status', 'is_featured',
            'source_name', 'source_url', 'sources', 'published_at',
            'meta_title', 'meta_description', 'focus_keyword', 'views',
        ]))->all();
        Storage::disk('local')->put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'count' => $total,
            'posts' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Backup written: storage/app/private/{$path}");

        if (! $this->option('apply')) {
            $this->warn("DRY RUN — nothing deleted. Re-run with --apply to delete all {$total} posts.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete ALL {$total} posts? This cannot be undone.", false)) {
            $this->info('Aborted. Nothing deleted.');

            return self::SUCCESS;
        }

        // Raw delete; DB-level ON DELETE CASCADE / SET NULL handle dependents.
        $deleted = DB::table('posts')->delete();

        $this->info("Deleted {$deleted} posts (comments, social posts and tag links cascaded).");
        $this->info('Next: clear caches and regenerate sitemaps —');
        $this->line('  php artisan view:clear && php artisan cache:clear');

        return self::SUCCESS;
    }
}
