<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ShopController extends Controller
{
    public function index()
    {
        $products = Product::active()->orderBy('sort_order')->get();

        return view('shop.index', compact('products'));
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $related = Product::active()
            ->whereKeyNot($product->id)
            ->inRandomOrder()
            ->take(4)->get();

        return view('shop.show', compact('product', 'related'));
    }
}
