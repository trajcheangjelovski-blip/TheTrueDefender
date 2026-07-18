<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Breaking-news flag for the top ticker. breaking_until lets it
            // auto-expire so stale stories drop off on their own (null = manual).
            $table->boolean('is_breaking')->default(false);
            $table->timestamp('breaking_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['is_breaking', 'breaking_until']);
        });
    }
};
