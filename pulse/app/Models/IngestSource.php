<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestSource extends Model
{
    protected $fillable = [
        'name', 'feed_url', 'category_id', 'author_id', 'is_active',
        'auto_publish', 'fetch_images', 'ai_image', 'max_items', 'last_fetched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_publish' => 'boolean',
        'fetch_images' => 'boolean',
        'ai_image' => 'boolean',
        'max_items' => 'integer',
        'last_fetched_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IngestedItem::class);
    }
}
