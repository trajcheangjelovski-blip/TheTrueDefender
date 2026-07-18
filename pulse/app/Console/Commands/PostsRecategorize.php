<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use App\Services\Rewriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:recategorize {--all : Include manually-created posts too (default: only ingested articles)}')]
#[Description('Re-sort posts into the correct category using AI (by article content)')]
class PostsRecategorize extends Command
{
    public function handle(Rewriter $rewriter): int
    {
        $categories = Category::where('is_active', true)
            ->whereNotIn('slug', ['opinion'])
            ->get(['id', 'slug', 'name', 'icon']);
        $catList = $categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->all();

        $query = Post::query();
        if (! $this->option('all')) {
            $query->whereNotNull('source_url'); // real ingested articles only
        }

        $moved = 0;
        foreach ($query->get() as $post) {
            $slug = $rewriter->classifyCategory($post->title, (string) $post->body, $catList);
            $cat = $categories->firstWhere('slug', $slug);
            if ($cat && $cat->id !== $post->category_id) {
                $old = $post->category?->name;
                $post->forceFill(['category_id' => $cat->id, 'image_icon' => $cat->icon ?: $post->image_icon])->saveQuietly();
                $this->info("#{$post->id}: {$old} → {$cat->name}  ({$post->title})");
                $moved++;
            }
        }

        $this->info("Recategorized {$moved} post(s).");

        return self::SUCCESS;
    }
}
