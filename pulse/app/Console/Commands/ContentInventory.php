<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Console\Command;

class ContentInventory extends Command
{
    protected $signature = 'content:inventory';
    protected $description = 'Read-only article inventory: published, future, drafts, archived and category totals.';

    public function handle(): int
    {
        $this->table(['State', 'Articles'], [
            ['Visible published', Post::published()->count()],
            ['Published with future date', Post::where('status', 'published')->where('published_at', '>', now())->count()],
            ['Published missing date', Post::where('status', 'published')->whereNull('published_at')->count()],
            ['Draft', Post::where('status', 'draft')->count()],
            ['Archived', Post::where('status', 'archived')->count()],
        ]);
        $this->table(['Category', 'Active', 'Visible published', 'All statuses'],
            Category::orderBy('sort_order')->get()->map(fn ($category) => [
                $category->name, $category->is_active ? 'yes' : 'no',
                $category->posts()->published()->count(), $category->posts()->count(),
            ])->all());
        return self::SUCCESS;
    }
}
