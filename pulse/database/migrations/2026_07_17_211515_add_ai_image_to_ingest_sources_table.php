<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_sources', function (Blueprint $table) {
            // When fetch_images is on: generate an original AI image instead of
            // copying the source photo (safer, avoids republishing their image).
            $table->boolean('ai_image')->default(false)->after('fetch_images');
        });
    }

    public function down(): void
    {
        Schema::table('ingest_sources', function (Blueprint $table) {
            $table->dropColumn('ai_image');
        });
    }
};
