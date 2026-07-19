<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'color', 'size', 'style', 'price', 'sale_price',
        'sku', 'stock', 'image', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Human label, e.g. "Red / Large". Empty axes are skipped. */
    public function getLabelAttribute(): string
    {
        return collect([$this->color, $this->size, $this->style])
            ->filter(fn ($v) => filled($v))
            ->implode(' / ');
    }

    /**
     * Effective price the customer pays for this variant. A variant may override
     * the product's price and/or sale price; anything left blank inherits from
     * the parent product.
     */
    public function getCurrentPriceAttribute(): float
    {
        $base = $this->price !== null ? (float) $this->price : (float) $this->product->price;
        $sale = $this->sale_price !== null
            ? (float) $this->sale_price
            : ($this->price === null ? ($this->product->sale_price !== null ? (float) $this->product->sale_price : null) : null);

        return (float) ($sale ?? $base);
    }

    public function getRegularPriceAttribute(): float
    {
        return $this->price !== null ? (float) $this->price : (float) $this->product->price;
    }

    public function getOnSaleAttribute(): bool
    {
        return $this->current_price < $this->regular_price;
    }
}
