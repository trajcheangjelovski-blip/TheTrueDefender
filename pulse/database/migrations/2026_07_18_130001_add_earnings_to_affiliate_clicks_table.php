<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            // Earnings locked in at click time (rate × share as of that moment),
            // so changing rates later never re-prices already-earned clicks.
            $table->decimal('earnings', 8, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            $table->dropColumn('earnings');
        });
    }
};
