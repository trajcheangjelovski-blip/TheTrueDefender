<?php

namespace App\Models;

use App\Observers\PostObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[ObservedBy([PostObserver::class])]
class Post extends Model
{
    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'category_id', 'author_id',
        'featured_image', 'image_icon', 'status', 'is_featured', 'published_at',
        'views', 'source_name', 'source_url', 'push_notified_at', 'social_posted_at',
        'meta_title', 'meta_description', 'focus_keyword',
        'seo_score', 'seo_analysis', 'seo_analyzed_at',
        'gsc_position', 'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_synced_at',
        'is_breaking', 'breaking_until', 'is_trending', 'trending_until',
        'allow_comments',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'is_breaking' => 'boolean',
        'breaking_until' => 'datetime',
        'is_trending' => 'boolean',
        'trending_until' => 'datetime',
        'published_at' => 'datetime',
        'push_notified_at' => 'datetime',
        'social_posted_at' => 'datetime',
        'views' => 'integer',
        'seo_analysis' => 'array',
        'seo_analyzed_at' => 'datetime',
        'gsc_position' => 'float',
        'gsc_ctr' => 'float',
        'gsc_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            if (blank($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            if ($post->status === 'published' && blank($post->published_at)) {
                $post->published_at = now();
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }

    /** Posts currently flagged breaking (not yet expired). */
    public function scopeBreakingActive(Builder $query): Builder
    {
        return $query->where('is_breaking', true)
            ->where(fn (Builder $q) => $q->whereNull('breaking_until')->orWhere('breaking_until', '>=', now()));
    }

    /** Is this post's breaking flag currently live? */
    public function getIsBreakingNowAttribute(): bool
    {
        return $this->is_breaking
            && ($this->breaking_until === null || $this->breaking_until->isFuture());
    }

    /** Posts currently pinned as trending (not yet expired). */
    public function scopeTrendingActive(Builder $query): Builder
    {
        return $query->where('is_trending', true)
            ->where(fn (Builder $q) => $q->whereNull('trending_until')->orWhere('trending_until', '>=', now()));
    }

    public function getIsTrendingNowAttribute(): bool
    {
        return $this->is_trending
            && ($this->trending_until === null || $this->trending_until->isFuture());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Approved TOP-LEVEL comments, oldest first (replies load separately). */
    public function approvedComments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->comments()->approved()->whereNull('parent_id')->oldest();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Human "x hours ago" for the front-end meta rows. */
    public function getTimeAgoAttribute(): string
    {
        return optional($this->published_at)->diffForHumans() ?? '';
    }

    /**
     * Public URL of the featured image at the right size for its placement
     * (hero 1600x900 | card 800x450 | thumb 400x225). Falls back to the
     * original file when the variant doesn't exist; null when no image.
     */
    public function imageUrl(?string $size = null): ?string
    {
        if (blank($this->featured_image)) {
            return null;
        }

        if ($size !== null) {
            $variant = preg_replace('/\.[^.]+$/', '', $this->featured_image) . "-{$size}.jpg";
            if (file_exists(storage_path('app/public/' . $variant))) {
                return asset('storage/' . $variant);
            }
        }

        return asset('storage/' . $this->featured_image);
    }

    /** Estimated reading time in minutes (~200 wpm). */
    public function getReadingMinutesAttribute(): int
    {
        $words = str_word_count(strip_tags($this->body ?? ''));

        return max(1, (int) ceil($words / 200));
    }

    /**
     * Split the body at a paragraph boundary near the middle, so an
     * in-article ad can sit between the halves. Returns [first, second|null].
     */
    public function bodyParts(): array
    {
        $body = (string) ($this->body ?? '');
        $paragraphs = array_values(array_filter(
            preg_split('/<\/p>/i', $body),
            fn ($p) => trim($p) !== '',
        ));

        if (count($paragraphs) < 2) {
            return [$body, null];
        }

        $mid = (int) ceil(count($paragraphs) / 2);
        $first = implode('</p>', array_slice($paragraphs, 0, $mid)) . '</p>';
        $second = implode('</p>', array_slice($paragraphs, $mid)) . '</p>';

        return [$first, $second];
    }
}
