<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price', 'sale_price', 'sku', 'stock',
        'track_stock', 'image', 'image_icon', 'tag', 'is_active', 'sort_order',
        'shipping_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'is_active' => 'boolean',
        'track_stock' => 'boolean',
        'stock' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (blank($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Effective price customers pay (sale price if set). */
    public function getCurrentPriceAttribute(): float
    {
        return (float) ($this->sale_price ?? $this->price);
    }

    public function getOnSaleAttribute(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price < (float) $this->price;
    }

    /** "FREE — just pay shipping" product (price 0, shipping charged). */
    public function getIsFreeAttribute(): bool
    {
        return $this->current_price == 0.0;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
