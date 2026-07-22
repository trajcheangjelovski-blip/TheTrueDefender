<?php

namespace App\Filament\Pages;

use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class ManageMedia extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Media & Storage';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-media';

    /** Directories that hold user media (relative to the 'public' disk). */
    private const DIRS = ['posts', 'products'];

    /** Max files to list in the table (stats always cover everything). */
    private const LIST_LIMIT = 400;

    public string $filter = 'unused'; // unused | used | all

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_settings') ?? false;
    }

    /** Paths referenced by the database, plus their derived size-variants. */
    private function usedPaths(): array
    {
        $bases = collect()
            ->merge(Post::whereNotNull('featured_image')->pluck('featured_image'))
            ->merge(Product::whereNotNull('image')->pluck('image'))
            ->merge(ProductVariant::whereNotNull('image')->pluck('image'))
            ->filter()
            ->unique();

        $used = [];
        foreach ($bases as $base) {
            $used[$base] = true;
            // Post images generate -hero/-card/-thumb JPEG variants next to them.
            $stem = preg_replace('/\.[^.]+$/', '', $base);
            foreach (['hero', 'card', 'thumb'] as $v) {
                $used["{$stem}-{$v}.jpg"] = true;
            }
        }

        return $used;
    }

    /** Scan disk → per-file info + totals. */
    public function report(): array
    {
        $disk = Storage::disk('public');
        $used = $this->usedPaths();

        $files = [];
        $totBytes = $usedBytes = $unusedBytes = 0;
        $usedCount = $unusedCount = 0;

        foreach (self::DIRS as $dir) {
            foreach ($disk->files($dir) as $path) {
                $size = $disk->size($path);
                $isUsed = isset($used[$path]);
                $totBytes += $size;
                if ($isUsed) { $usedBytes += $size; $usedCount++; }
                else { $unusedBytes += $size; $unusedCount++; }

                $files[] = [
                    'path' => $path,
                    'url' => $disk->url($path),
                    'size' => $size,
                    'human' => $this->human($size),
                    'used' => $isUsed,
                ];
            }
        }

        // Show unused (actionable) first, largest first.
        usort($files, fn ($a, $b) => [$a['used'], $b['size']] <=> [$b['used'], $a['size']]);

        $shown = array_filter($files, fn ($f) => $this->filter === 'all'
            || ($this->filter === 'unused' && ! $f['used'])
            || ($this->filter === 'used' && $f['used']));

        return [
            'total' => ['count' => count($files), 'size' => $this->human($totBytes)],
            'used' => ['count' => $usedCount, 'size' => $this->human($usedBytes)],
            'unused' => ['count' => $unusedCount, 'size' => $this->human($unusedBytes)],
            'files' => array_slice(array_values($shown), 0, self::LIST_LIMIT),
            'shownCount' => count($shown),
            'listLimit' => self::LIST_LIMIT,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteUnused')
                ->label('Delete all unused')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete all unused media')
                ->modalDescription(function () {
                    $r = $this->report();
                    return "This permanently deletes {$r['unused']['count']} unused file(s) and frees {$r['unused']['size']}. Files still linked to a post or product are kept.";
                })
                ->modalSubmitActionLabel('Delete unused files')
                ->action(fn () => $this->deleteAllUnused()),
        ];
    }

    public function deleteAllUnused(): void
    {
        $disk = Storage::disk('public');
        $used = $this->usedPaths();
        $freed = 0; $n = 0;

        foreach (self::DIRS as $dir) {
            foreach ($disk->files($dir) as $path) {
                if (! isset($used[$path])) {
                    $freed += $disk->size($path);
                    $disk->delete($path);
                    $n++;
                }
            }
        }

        Notification::make()
            ->title("Deleted {$n} unused file(s), freed {$this->human($freed)}")
            ->success()->send();
    }

    /** Delete a single file — only if it's genuinely unused, and path is safe. */
    public function deleteFile(string $path): void
    {
        $safe = collect(self::DIRS)->contains(fn ($d) => str_starts_with($path, $d . '/'))
            && ! str_contains($path, '..');

        if (! $safe || isset($this->usedPaths()[$path])) {
            Notification::make()->title('That file is in use or not allowed — skipped.')->warning()->send();

            return;
        }

        Storage::disk('public')->delete($path);
        Notification::make()->title('File deleted.')->success()->send();
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), $i ? 1 : 0) . ' ' . $units[$i];
    }
}
