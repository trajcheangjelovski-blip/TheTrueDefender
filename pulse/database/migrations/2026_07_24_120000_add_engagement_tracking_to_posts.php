<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Headline impressions (shown in a list) and clicks → real hook CTR.
            $table->unsignedInteger('impressions')->default(0)->after('views');
            $table->unsignedInteger('clicks')->default(0)->after('impressions');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['impressions', 'clicks']);
        });
    }
};
