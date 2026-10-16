<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\ArticleSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * THE VOLUME CUT. Retire the low-value, scaled-content tail that triggers
 * AdSense "low value content", keeping only a curated set of strong articles.
 *
 * Makes NO OpenAI calls. Reversible by default: retired posts are set to
 * status=archived (served as HTTP 410 Gone so Google deindexes them fast) and a
 * full JSON backup is written first. --undo restores from a backup. --purge
 * hard-deletes instead (after the same backup) — use only when you are sure.
 *
 * SELECTION
 *   Default: retire every published post that looks like a thin single-source
 *   rewrite — fewer than --min-sources distinct sources, OR under --min-words,
 *   OR carrying an AI-tell / outreach phrase, OR with no source recorded.
 *   --all: retire EVERY published post (clean slate for the new model).
 *
 * WHAT TO KEEP (applied on top of either selection)
 *   --keep-featured      keep posts flagged is_featured
 *   --keep-top=N         keep the N most-viewed published posts
 *   --keep-slugs=a,b,c   keep these exact slugs
 *   --keep-category=slug keep everything in this category (repeatable, comma-sep)
 *
 * EXAMPLES
 *   php artisan posts:prune-thin                         # dry run: show what would go
 *   php artisan posts:prune-thin --apply                 # archive the thin tail (reversible)
 *   php artisan posts:prune-thin --all --keep-top=50 --apply
 *   php artisan posts:prune-thin --undo=backups/prune-20261016-101500.json
 *   php artisan posts:prune-thin --all --keep-slugs=my-flagship --purge --apply
 */
class PostsPruneThin extends Command
{
    protected $signature = 'posts:prune-thin
        {--apply : Actually retire posts (without this flag it is a dry run)}
        {--all : Retire ALL published posts, not just the thin ones}
        {--min-words=600 : Posts shorter than this count as thin}
        {--min-sources=2 : Posts with fewer distinct sources than this count as thin}
        {--keep-featured : Never retire posts flagged is_featured}
        {--keep-top=0 : Keep the N most-viewed published posts}
        {--keep-slugs= : Comma-separated slugs to always keep}
        {--keep-category= : Comma-separated category slugs to always keep}
        {--purge : HARD DELETE instead of archiving (irreversible; backup still written)}
        {--undo= : Restore statuses from a backup JSON written by a previous run}';

    protected $description = 'Volume cut: retire the thin single-source tail (reversible archive→410 by default). No AI calls.';

    private const AI_TELLS = [
        'the new report says', 'the new report adds', 'the report says', 'the report adds',
        'the report also says', 'according to the report', 'the article says', 'the source article',
    ];

    private const OUTREACH = [
        'did not respond to a request for comment', 'did not respond to requests for comment',
        'did not immediately respond to a request for comment', 'we reached out', 'we contacted',
        'told the true defender', 'in a statement to the true defender',
    ];

    public function handle(): int
    {
        if ($undo = $this->option('undo')) {
            return $this->undo($undo);
        }

        $minWords = (int) $this->option('min-words');
        $minSources = (int) $this->option('min-sources');
        $apply = (bool) $this->option('apply');
        $purge = (bool) $this->option('purge');

        $keepSlugs = collect(explode(',', (string) $this->option('keep-slugs')))
            ->map(fn ($s) => trim($s))->filter()->values()->all();
        $keepCats = collect(explode(',', (string) $this->option('keep-category')))
            ->map(fn ($s) => trim($s))->filter()->values()->all();

        $posts = Post::query()->where('status', 'published')
            ->with('category:id,slug')
            ->get(['id', 'slug', 'title', 'body', 'excerpt', 'status', 'views', 'is_featured', 'source_url', 'sources', 'category_id', 'published_at']);

        // The "keep" set.
        $keepIds = collect();
        if ($this->option('keep-featured')) {
            $keepIds = $keepIds->merge($posts->where('is_featured', true)->pluck('id'));
        }
        if (($topN = (int) $this->option('keep-top')) > 0) {
            $keepIds = $keepIds->merge($posts->sortByDesc('views')->take($topN)->pluck('id'));
        }
        if ($keepSlugs) {
            $keepIds = $keepIds->merge($posts->whereIn('slug', $keepSlugs)->pluck('id'));
        }
        if ($keepCats) {
            $keepIds = $keepIds->merge($posts->filter(fn ($p) => in_array($p->category?->slug, $keepCats, true))->pluck('id'));
        }
        $keepIds = $keepIds->unique()->flip();

        $retire = [];
        foreach ($posts as $post) {
            if ($keepIds->has($post->id)) {
                continue;
            }

            $reasons = $this->option('all') ? ['all'] : $this->thinReasons($post, $minWords, $minSources);
            if ($reasons) {
                $retire[] = ['post' => $post, 'reasons' => $reasons];
            }
        }

        $total = $posts->count();
        $toRetire = count($retire);
        $keeping = $total - $toRetire;

        $this->info("Published posts: {$total}");
        $this->info("Would keep:      {$keeping}");
        $this->info(($apply ? 'Retiring' : 'Would retire') . ":   {$toRetire}" . ($purge ? '  (HARD DELETE)' : '  (archive → 410 Gone)'));
        $this->newLine();

        foreach (array_slice($retire, 0, 25) as $r) {
            $this->line('  /post/' . $r['post']->slug . '  [' . implode(',', array_keys($r['reasons'])) . ']  — ' . Str::limit($r['post']->title, 55));
        }
        if ($toRetire > 25) {
            $this->line('  … and ' . ($toRetire - 25) . ' more.');
        }
        $this->newLine();

        if ($toRetire === 0) {
            $this->info('Nothing to retire.');

            return self::SUCCESS;
        }

        // Always write a backup (even on dry run, so there is a record of the plan).
        $stamp = now()->format('Ymd-His');
        $backupPath = "backups/prune-{$stamp}.json";
        Storage::disk('local')->put($backupPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'mode' => $purge ? 'purge' : 'archive',
            'applied' => $apply,
            'posts' => collect($retire)->map(fn ($r) => [
                'id' => $r['post']->id,
                'slug' => $r['post']->slug,
                'title' => $r['post']->title,
                'previous_status' => $r['post']->status,
                'reasons' => array_keys($r['reasons']),
            ])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Backup written: storage/app/private/{$backupPath}");

        if (! $apply) {
            $this->warn('DRY RUN — nothing changed. Re-run with --apply to retire these posts.');

            return self::SUCCESS;
        }

        $ids = collect($retire)->map(fn ($r) => $r['post']->id);
        if ($purge) {
            $deleted = Post::whereIn('id', $ids)->delete();
            $this->warn("HARD DELETED {$deleted} posts. Restore is only possible by re-importing; keep the backup safe.");
        } else {
            $changed = Post::whereIn('id', $ids)->update(['status' => 'archived']);
            $this->info("Archived {$changed} posts (now serve HTTP 410 Gone). Undo: php artisan posts:prune-thin --undo={$backupPath}");
        }

        $this->newLine();
        $this->info('Next: regenerate sitemaps and clear caches so the removed URLs drop out.');

        return self::SUCCESS;
    }

    /** Reasons a post counts as thin/low-value, or [] if it looks substantial. */
    private function thinReasons(Post $post, int $minWords, int $minSources): array
    {
        $reasons = [];
        $plain = mb_strtolower(strip_tags((string) $post->body));
        $words = str_word_count(strip_tags((string) $post->body));

        $sourceCount = is_array($post->sources) ? count(array_filter($post->sources)) : 0;
        if ($sourceCount === 0 && filled($post->source_url)) {
            $sourceCount = 1;
        }

        if ($words < $minWords) {
            $reasons['thin'] = $words;
        }
        if ($sourceCount < $minSources) {
            $reasons['single-source'] = $sourceCount;
        }
        if ($sourceCount === 0) {
            $reasons['no-source'] = true;
        }
        foreach (self::AI_TELLS as $n) {
            if (str_contains($plain, $n)) {
                $reasons['ai-tell'] = true;
                break;
            }
        }
        foreach (self::OUTREACH as $n) {
            if (str_contains($plain, $n)) {
                $reasons['outreach'] = true;
                break;
            }
        }
        if (ArticleSanitizer::hasPlaceholder($post->body) || ArticleSanitizer::hasPlaceholder($post->excerpt)) {
            $reasons['placeholder'] = true;
        }

        return $reasons;
    }

    private function undo(string $path): int
    {
        if (! Storage::disk('local')->exists($path)) {
            $this->error("Backup not found: storage/app/private/{$path}");

            return self::FAILURE;
        }

        $data = json_decode(Storage::disk('local')->get($path), true);
        if (($data['mode'] ?? null) === 'purge') {
            $this->error('This backup was a HARD DELETE (purge). The rows are gone and cannot be restored from a status backup.');

            return self::FAILURE;
        }

        $restored = 0;
        foreach ($data['posts'] ?? [] as $row) {
            $prev = $row['previous_status'] ?? 'published';
            $restored += Post::where('id', $row['id'])->update(['status' => $prev]);
        }

        $this->info("Restored {$restored} posts to their previous status.");

        return self::SUCCESS;
    }
}
