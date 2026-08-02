<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag) {
            if (blank($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    public function publishedPosts(): BelongsToMany
    {
        return $this->posts()->published();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Find or create tags by their display names and return their IDs, ready for
     * a sync(). Normalizes names to a clean, consistent Title-Case topic and
     * de-duplicates by slug so "US Border" and "us border" become one topic.
     *
     * @param  array<int,string>  $names
     * @return array<int,int>
     */
    public static function idsForNames(array $names): array
    {
        $ids = [];

        foreach ($names as $raw) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $raw));
            if ($name === '' || mb_strlen($name) > 40) {
                continue;
            }

            $slug = Str::slug($name);
            if ($slug === '' || isset($ids[$slug])) {
                continue;
            }

            $tag = static::firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::title($name)],
            );
            $ids[$slug] = $tag->id;
        }

        return array_values($ids);
    }
}
