<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // On-page SEO (editable + AI-suggested)
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('focus_keyword')->nullable();

            // AI on-page analysis
            $table->unsignedTinyInteger('seo_score')->nullable();      // 0-100
            $table->json('seo_analysis')->nullable();                  // checks + suggestions
            $table->timestamp('seo_analyzed_at')->nullable();

            // Google Search Console (real ranking data)
            $table->decimal('gsc_position', 6, 2)->nullable();         // avg position
            $table->unsignedInteger('gsc_clicks')->nullable();
            $table->unsignedInteger('gsc_impressions')->nullable();
            $table->decimal('gsc_ctr', 5, 2)->nullable();             // %
            $table->timestamp('gsc_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'meta_title', 'meta_description', 'focus_keyword',
                'seo_score', 'seo_analysis', 'seo_analyzed_at',
                'gsc_position', 'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_synced_at',
            ]);
        });
    }
};
