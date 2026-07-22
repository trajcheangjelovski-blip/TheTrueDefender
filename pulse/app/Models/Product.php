<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'short_description', 'details',
        'price', 'sale_price', 'sku', 'stock',
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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Active, purchasable variants only. */
    public function activeVariants(): Collection
    {
        return $this->variants->where('is_active', true)->values();
    }

    public function hasVariants(): bool
    {
        return $this->activeVariants()->isNotEmpty();
    }

    /**
     * Distinct option values per axis, in first-seen order, for axes that are
     * actually used — e.g. ['Color' => ['Red','Blue'], 'Size' => ['S','M','L']].
     *
     * @return array<string,array<int,string>>
     */
    public function optionAxes(): array
    {
        $axes = [];
        foreach (['color' => 'Color', 'size' => 'Size', 'style' => 'Style'] as $field => $label) {
            $values = $this->activeVariants()
                ->pluck($field)
                ->filter(fn ($v) => filled($v))
                ->unique()
                ->values()
                ->all();
            if (! empty($values)) {
                $axes[$label] = $values;
            }
        }

        return $axes;
    }

    /** Lowest current price across active variants (for a "from $X" label). */
    public function getPriceFromAttribute(): float
    {
        $prices = $this->activeVariants()->map(fn (ProductVariant $v) => $v->current_price);

        return $prices->isEmpty() ? $this->current_price : (float) $prices->min();
    }

    /** Do active variants have differing prices (so we show "from")? */
    public function getHasPriceRangeAttribute(): bool
    {
        $prices = $this->activeVariants()->map(fn (ProductVariant $v) => $v->current_price)->unique();

        return $prices->count() > 1;
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

    /** All-in price the customer pays: item price + shipping. Shown as "$X delivered". */
    public function getDeliveredPriceAttribute(): float
    {
        return round((float) $this->current_price + (float) $this->shipping_price, 2);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
