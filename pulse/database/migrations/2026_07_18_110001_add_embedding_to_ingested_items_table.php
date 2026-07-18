<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_items', function (Blueprint $table) {
            // OpenAI embedding of the source title+summary, used for
            // cross-feed duplicate detection (cosine similarity).
            $table->json('embedding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_items', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
