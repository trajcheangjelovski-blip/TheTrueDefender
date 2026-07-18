<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Per-item shipping charge. Set price to 0 (or sale_price 0) and a
            // shipping_price to sell "FREE — just pay shipping" products.
            $table->decimal('shipping_price', 8, 2)->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable();      // stripe | cod
            $table->string('stripe_session_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shipping_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'stripe_session_id', 'paid_at']);
        });
    }
};
