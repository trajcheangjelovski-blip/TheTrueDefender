<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Working World feeds. The only World feed (Resist the Mainstream World) had
     * delivered nothing in 48 hours, so the World section — which now has a
     * reserved daily slot — never received a story. Same outlets as the existing
     * politics/US feeds; they go through the normal pipeline (score → multi-source
     * research + synthesis → PublishGate → paced promotion).
     */
    private const FEEDS = [
        ['name' => 'Fox News World', 'feed_url' => 'https://moxie.foxnews.com/google-publisher/world.xml'],
        ['name' => 'New York Post World', 'feed_url' => 'https://nypost.com/world-news/feed/'],
    ];

    public function up(): void
    {
        $categoryId = DB::table('categories')->where('slug', 'world')->value('id');
        if (! $categoryId) {
            return;
        }
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
                    'max_items' => 8,
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
