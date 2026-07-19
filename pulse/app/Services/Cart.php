<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class Cart
{
    private const KEY = 'cart';

    /**
     * Raw cart lines, keyed by a composite product+variant key.
     *
     * @return array<string,array{product_id:int,variant_id:?int,qty:int}>
     */
    private function raw(): array
    {
        $items = session()->get(self::KEY, []);
        $normalized = [];

        foreach ($items as $key => $value) {
            // New format: ['product_id'=>, 'variant_id'=>, 'qty'=>].
            if (is_array($value) && isset($value['product_id'])) {
                $normalized[$key] = [
                    'product_id' => (int) $value['product_id'],
                    'variant_id' => $value['variant_id'] !== null ? (int) $value['variant_id'] : null,
                    'qty' => (int) $value['qty'],
                ];
                continue;
            }

            // Legacy format: [productId => qty]. Migrate it in place.
            $normalized['p' . $key] = [
                'product_id' => (int) $key,
                'variant_id' => null,
                'qty' => (int) $value,
            ];
        }

        return $normalized;
    }

    private function save(array $items): void
    {
        session()->put(self::KEY, $items);
    }

    /** Composite key so the same product with different variants are separate lines. */
    public static function key(int $productId, ?int $variantId = null): string
    {
        return 'p' . $productId . ($variantId ? '-v' . $variantId : '');
    }

    public function add(int $productId, int $qty = 1, ?int $variantId = null): void
    {
        $items = $this->raw();
        $key = self::key($productId, $variantId);
        $items[$key] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => ($items[$key]['qty'] ?? 0) + max(1, $qty),
        ];
        $this->save($items);
    }

    public function update(string $key, int $qty): void
    {
        $items = $this->raw();
        if (! isset($items[$key])) {
            return;
        }
        if ($qty <= 0) {
            unset($items[$key]);
        } else {
            $items[$key]['qty'] = $qty;
        }
        $this->save($items);
    }

    public function remove(string $key): void
    {
        $items = $this->raw();
        unset($items[$key]);
        $this->save($items);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    /** Total quantity of items (for the nav badge). */
    public function count(): int
    {
        return array_sum(array_column($this->raw(), 'qty'));
    }

    /**
     * Resolve cart into line items with live product/variant data.
     *
     * @return Collection<int,array{key:string,product:Product,variant:?ProductVariant,quantity:int,unit_price:float,line_total:float}>
     */
    public function lines(): Collection
    {
        $raw = $this->raw();
        if (empty($raw)) {
            return collect();
        }

        $products = Product::active()->findMany(collect($raw)->pluck('product_id')->unique())->keyBy('id');
        $variants = ProductVariant::with('product')
            ->findMany(collect($raw)->pluck('variant_id')->filter()->unique())
            ->keyBy('id');

        return collect($raw)
            ->map(function (array $entry, string $key) use ($products, $variants) {
                $product = $products->get($entry['product_id']);
                if (! $product) {
                    return null; // product deactivated/deleted — drop the line
                }
                $variant = $entry['variant_id'] ? $variants->get($entry['variant_id']) : null;
                $unit = $variant ? $variant->current_price : $product->current_price;
                $qty = (int) $entry['qty'];

                return [
                    'key' => $key,
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => round($unit * $qty, 2),
                ];
            })
            ->filter()
            ->values();
    }

    public function subtotal(): float
    {
        return (float) $this->lines()->sum('line_total');
    }

    /** Total shipping: per-item shipping price × quantity (product-level). */
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
