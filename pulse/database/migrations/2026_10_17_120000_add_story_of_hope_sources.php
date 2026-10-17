<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Real uplifting-news feeds for the Story of Hope section. The existing feeds are
     * all politics / US / world, so the section never received a genuinely uplifting
     * story. These go through the normal pipeline: editorial score → multi-source
     * research + synthesis → PublishGate → paced promotion (one reserved slot a day).
     */
    private const FEEDS = [
        ['name' => 'Good News Network', 'feed_url' => 'https://www.goodnewsnetwork.org/feed/'],
        ['name' => 'Sunny Skyz', 'feed_url' => 'https://www.sunnyskyz.com/rss_tebow.php'],
        ['name' => 'Upworthy', 'feed_url' => 'https://www.upworthy.com/feeds/feed.rss'],
    ];

    public function up(): void
    {
        $categoryId = DB::table('categories')->where('slug', 'story-of-hope')->value('id');
        if (! $categoryId) {
            return; // nothing to attach the feeds to
        }

        // Match the existing feeds' image settings and fallback author.
        $template = DB::table('ingest_sources')->where('is_active', true)->orderBy('id')->first();

        foreach (self::FEEDS as $feed) {
            DB::table('ingest_sources')->updateOrInsert(
                ['feed_url' => $feed['feed_url']],
                [
                    'name' => $feed['name'],
                    'category_id' => $categoryId,
                    'author_id' => $template->author_id ?? null,
                    'is_active' => true,
                    'auto_publish' => true,
                    'fetch_images' => (bool) ($template->fetch_images ?? true),
                    'ai_image' => (bool) ($template->ai_image ?? true),
                    'max_items' => 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('ingest_sources')->whereIn('feed_url', array_column(self::FEEDS, 'feed_url'))->delete();
    }
};
