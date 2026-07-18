<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class Cart
{
    private const KEY = 'cart';

    /** @return array<int,int> productId => quantity */
    private function raw(): array
    {
        return session()->get(self::KEY, []);
    }

    private function save(array $items): void
    {
        session()->put(self::KEY, $items);
    }

    public function add(int $productId, int $qty = 1): void
    {
        $items = $this->raw();
        $items[$productId] = ($items[$productId] ?? 0) + max(1, $qty);
        $this->save($items);
    }

    public function update(int $productId, int $qty): void
    {
        $items = $this->raw();
        if ($qty <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = $qty;
        }
        $this->save($items);
    }

    public function remove(int $productId): void
    {
        $items = $this->raw();
        unset($items[$productId]);
        $this->save($items);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    /** Total quantity of items (for the nav badge). */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /**
     * Resolve cart into line items with live product data.
     *
     * @return Collection<int,array{product:Product,quantity:int,line_total:float}>
     */
    public function lines(): Collection
    {
        $items = $this->raw();
        if (empty($items)) {
            return collect();
        }

        return Product::active()->findMany(array_keys($items))
            ->map(function (Product $product) use ($items) {
                $qty = $items[$product->id];
                return [
                    'product' => $product,
                    'quantity' => $qty,
                    'line_total' => round($product->current_price * $qty, 2),
                ];
            })
            ->values();
    }

    public function subtotal(): float
    {
        return (float) $this->lines()->sum('line_total');
    }

    /** Total shipping: per-item shipping price × quantity. */
    public function shipping(): float
    {
        return round((float) $this->lines()->sum(
            fn (array $line) => (float) $line['product']->shipping_price * $line['quantity'],
        ), 2);
    }

    public function total(): float
    {
        return round($this->subtotal() + $this->shipping(), 2);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
