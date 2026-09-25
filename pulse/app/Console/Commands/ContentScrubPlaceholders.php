<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\ArticleSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scrubs leftover placeholder/template tokens (e.g. a literal "[[LINK]]") from
 * already-published article bodies, excerpts, captions, takeaways and FAQs.
 *
 * REVERSIBLE: --apply writes a full backup of every affected row's original
 * field values to storage/app/private/backups/ before changing anything;
 * --restore=FILE puts them back exactly. Default run is a dry report.
 */
class ContentScrubPlaceholders extends Command
{
    protected $signature = 'content:scrub-placeholders
        {--apply : Write the cleaned values (a backup is saved first)}
        {--all : Include drafts/unpublished, not just published posts}
        {--restore= : Path to a backup JSON file to restore instead of scrubbing}';

    protected $description = 'Remove leftover placeholder tokens (e.g. [[LINK]]) from post content; reversible via backup/restore.';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            return $this->restore($file);
        }

        $apply = (bool) $this->option('apply');
        $affected = [];

        $query = $this->option('all') ? Post::query() : Post::query()->where('status', 'published');

        foreach ($query->get() as $post) {
            // Precise: only touch posts that actually contain a placeholder token
            // (clean() also normalizes <p> markup, so a value-diff would over-match).
            if (! $this->postHasPlaceholder($post)) {
                continue;
            }

            $affected[] = $this->snapshot($post);
            $tokens = $this->tokensIn($post);
            $this->line("#{$post->id}  /post/{$post->slug}");
            if ($tokens) {
                $this->line('    tokens: ' . implode('  ', $tokens));
            }
        }

        if (empty($affected)) {
            $this->info('No placeholder tokens found. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('[DRY RUN] ' . count($affected) . ' post(s) would be cleaned. Re-run with --apply to write (a backup is saved first).');

            return self::SUCCESS;
        }

        // Back up originals BEFORE touching anything, so the change is reversible.
        $backupPath = 'backups/scrub-placeholders-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($backupPath, json_encode($affected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Backup written: storage/app/private/' . $backupPath);

        foreach ($affected as $row) {
            $post = Post::find($row['id']);
            if ($post) {
                $post->forceFill($this->cleaned($post))->saveQuietly();
            }
        }

        $this->info('Cleaned ' . count($affected) . " post(s). Restore with:\n  php artisan content:scrub-placeholders --restore=" . $backupPath);

        return self::SUCCESS;
    }

    /** True if any user-visible field still holds a placeholder token. */
    private function postHasPlaceholder(Post $post): bool
    {
        if (ArticleSanitizer::hasPlaceholder($post->body)
            || ArticleSanitizer::hasPlaceholder($post->excerpt)
            || ArticleSanitizer::hasPlaceholder($post->social_text)) {
            return true;
        }

        foreach ((array) $post->takeaways as $t) {
            if (ArticleSanitizer::hasPlaceholder(is_array($t) ? ($t['text'] ?? '') : $t)) {
                return true;
            }
        }
        foreach ((array) $post->faqs as $f) {
            if (is_array($f) && (ArticleSanitizer::hasPlaceholder($f['question'] ?? '') || ArticleSanitizer::hasPlaceholder($f['answer'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /** Original field values we might change, for the backup. */
    private function snapshot(Post $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'fields' => [
                'body' => (string) $post->body,
                'excerpt' => (string) $post->excerpt,
                'social_text' => (string) $post->social_text,
                'takeaways' => $post->takeaways ?? [],
                'faqs' => $post->faqs ?? [],
            ],
        ];
    }

    /** The same fields after placeholder stripping. */
    private function cleaned(Post $post): array
    {
        $takeaways = collect($post->takeaways ?? [])
            ->map(fn ($t) => is_array($t)
                ? array_merge($t, ['text' => ArticleSanitizer::stripPlaceholders($t['text'] ?? '')])
                : ArticleSanitizer::stripPlaceholders((string) $t))
            ->all();

        $faqs = collect($post->faqs ?? [])
            ->map(fn ($f) => is_array($f) ? array_merge($f, [
                'question' => ArticleSanitizer::stripPlaceholders($f['question'] ?? ''),
                'answer' => ArticleSanitizer::stripPlaceholders($f['answer'] ?? ''),
            ]) : $f)
            ->all();

        return [
            'body' => ArticleSanitizer::clean($post->body),
            'excerpt' => ArticleSanitizer::cleanText($post->excerpt),
            'social_text' => ArticleSanitizer::cleanText($post->social_text) ?: null,
            'takeaways' => $takeaways,
            'faqs' => $faqs,
        ];
    }

    /** Human list of the actual tokens found (for the report). */
    private function tokensIn(Post $post): array
    {
        $hay = implode("\n", [
            (string) $post->body,
            (string) $post->excerpt,
            (string) $post->social_text,
            json_encode($post->takeaways),
            json_encode($post->faqs),
        ]);

        preg_match_all('/\[\[[^\]]*\]\]|\{\{[^}]*\}\}|[\[\{]\s*(?:link|url|href|source|src|citation needed|image|img|photo|video|quote|insert[^\]\}]*|placeholder|tbd|todo|xx+)\s*[\]\}]/iu', $hay, $m);

        return array_values(array_unique(array_map(fn ($t) => Str::limit(trim($t), 40), $m[0] ?? [])));
    }

    private function restore(string $file): int
    {
        $path = Storage::disk('local')->exists($file) ? Storage::disk('local')->get($file)
            : (is_file($file) ? file_get_contents($file) : null);

        if ($path === null) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode($path, true);
        if (! is_array($rows)) {
            $this->error('Backup file is not valid JSON.');

            return self::FAILURE;
        }

        $restored = 0;
        foreach ($rows as $row) {
            $post = Post::find($row['id'] ?? null);
            if ($post && isset($row['fields'])) {
                $post->forceFill($row['fields'])->saveQuietly();
                $restored++;
            }
        }

        $this->info("Restored {$restored} post(s) from {$file}.");

        return self::SUCCESS;
    }
}
