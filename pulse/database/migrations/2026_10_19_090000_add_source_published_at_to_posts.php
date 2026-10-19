<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the ORIGINAL outlet published the story (earliest of feed date and the
     * article page's published date). Drives freshness: PublishGate refuses to
     * publish a story older than max_publish_age_hours, and the queue ranks by it.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('source_published_at')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('source_published_at');
        });
    }
};
