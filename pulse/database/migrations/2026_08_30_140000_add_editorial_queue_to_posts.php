<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // The AI managing-editor priority score (0-100) at ingest time — kept on
            // the post so the paced promoter can rank queued stories against each other.
            $table->unsignedTinyInteger('editorial_score')->nullable()->after('source_url');
            // Set when a story is HELD for editorial pacing (good enough to run, but
            // not urgent — waits to be compared with later stories). NULL = not queued
            // (either already live, or a genuinely failed draft that fix-drafts owns).
            $table->timestamp('queued_at')->nullable()->after('editorial_score');
            $table->index('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['queued_at']);
            $table->dropColumn(['editorial_score', 'queued_at']);
        });
    }
};
