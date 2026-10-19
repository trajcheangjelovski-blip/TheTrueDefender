<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\ArticleSections;
use App\Support\PublishGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Remove switched-off sections (Key Takeaways, Our Take, Sources) from STORED
 * articles — rows written before the switches existed, or restored from a backup.
 *
 * Safe by construction:
 *  - dry run unless --apply; --apply first writes a private JSON backup of every
 *    row it will touch (id, slug, body, takeaways) and --restore=<file> puts them back;
 *  - only body + takeaways change, written directly (no model events): IDs, slugs/URLs,
 *    publication dates, images, SEO fields and updated_at stay exactly as they were;
 *  - only the unambiguous forms are removed (ArticleSections::strip); anything else
 *    that looks like a section is LISTED for an editor, never cut automatically.
 */
class PostsStripSections extends Command
{
    protected $signature = 'posts:strip-sections
        {--apply : Change the stored articles (a backup is written first)}
        {--restore= : Restore body/takeaways from a backup file written by --apply}';

    protected $description = 'Remove switched-off Key Takeaways / Our Take / Sources sections from stored articles (dry run by default, backup + rollback).';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            return $this->restore($file);
        }

        $apply = (bool) $this->option('apply');
        $changes = [];
        $review = [];

        Post::query()->orderBy('id')->chunk(200, function ($posts) use (&$changes, &$review) {
            foreach ($posts as $post) {
                $opinion = PublishGate::isOpinion($post);
                $raw = $post->getRawOriginal();
                $body = ArticleSections::strip($post->body, $opinion);
                $takeaways = ArticleSections::takeawaysEnabled() ? $raw['takeaways'] : null;

                if ($body !== (string) $post->body || $takeaways !== $raw['takeaways']) {
                    $changes[] = ['post' => $post, 'raw' => $raw, 'body' => $body, 'takeaways' => $takeaways];
                }
                if ($left = ArticleSections::found($body, $opinion)) {
                    $review[] = [$post->id, $post->status, Str::limit($post->title, 50), implode(', ', $left)];
                }
            }
        });

        $this->info(($apply ? '' : '[DRY RUN] ') . count($changes) . ' article(s) with removable sections; '
            . count($review) . ' need an editor (section could not be removed safely).');
        foreach (array_slice($changes, 0, 50) as $c) {
            $p = $c['post'];
            $this->line("  #{$p->id} [{$p->status}] " . Str::limit($p->title, 55)
                . ($c['takeaways'] !== $c['raw']['takeaways'] ? '  · takeaways cleared' : '')
                . ($c['body'] !== (string) $p->body ? '  · body: ' . (str_word_count(strip_tags((string) $p->body)) - str_word_count(strip_tags($c['body']))) . ' words removed' : ''));
        }
        if ($review) {
            $this->table(['id', 'status', 'title', 'section left in body'], $review);
        }

        if (! $apply || ! $changes) {
            return self::SUCCESS;
        }

        $path = 'backups/strip-sections-' . now()->format('Ymd-His') . '.json';
        $backup = array_map(fn ($c) => [
            'id' => $c['raw']['id'], 'slug' => $c['raw']['slug'],
            'body' => $c['raw']['body'], 'takeaways' => $c['raw']['takeaways'],
        ], $changes);
        if (! Storage::disk('local')->put($path, json_encode(['created_at' => now()->toIso8601String(), 'posts' => $backup], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            $this->error('Could not write the backup — nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $c) {
                DB::table('posts')->where('id', $c['raw']['id'])->update(['body' => $c['body'], 'takeaways' => $c['takeaways']]);
            }
        });

        $this->info('Updated ' . count($changes) . ' article(s). Backup: ' . Storage::disk('local')->path($path));
        $this->line('Roll back with: php artisan posts:strip-sections --restore=' . $path);

        return self::SUCCESS;
    }

    private function restore(string $file): int
    {
        $json = json_decode((string) Storage::disk('local')->get($file), true);
        if (! is_array($json['posts'] ?? null)) {
            $this->error("Not a strip-sections backup: {$file}");

            return self::FAILURE;
        }

        DB::transaction(function () use ($json) {
            foreach ($json['posts'] as $row) {
                DB::table('posts')->where('id', $row['id'])->update(['body' => $row['body'], 'takeaways' => $row['takeaways']]);
            }
        });
        $this->info('Restored ' . count($json['posts']) . ' article(s) from ' . $file . '.');

        return self::SUCCESS;
    }
}
