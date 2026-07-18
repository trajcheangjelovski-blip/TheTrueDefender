<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Editorial "trending" pin. Blends with view-count on the homepage;
            // trending_until lets an AI/manual flag auto-expire (null = manual).
            $table->boolean('is_trending')->default(false);
            $table->timestamp('trending_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['is_trending', 'trending_until']);
        });
    }
};
