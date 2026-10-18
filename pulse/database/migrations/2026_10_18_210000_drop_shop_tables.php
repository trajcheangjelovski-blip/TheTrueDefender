<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shop was removed. Drop its tables, but never discard order records: if any order
 * exists the migration leaves every shop table in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && DB::table('orders')->exists()) {
            return;
        }
        foreach (['order_items', 'orders', 'product_variants', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intentionally empty: restore from git history if the shop is ever needed again.
    }
};
