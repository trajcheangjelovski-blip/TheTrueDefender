<?php

namespace Database\Seeders;

use App\Models\AdPlacement;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /** Admin-panel permissions ("custom rules" for roles). */
    public const PERMISSIONS = [
        'manage_posts', 'manage_shop', 'manage_audience',
        'manage_automation', 'manage_ads', 'manage_settings', 'manage_users',
        'manage_affiliates',
    ];
    // Note: comment moderation is covered by the existing 'manage_audience' permission.

    public function run(): void
    {
        // ── Permissions + roles ──
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }
        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);
        $roleAdmin->syncPermissions(self::PERMISSIONS); // admin gets everything
        Role::firstOrCreate(['name' => 'editor'])->syncPermissions(['manage_posts', 'manage_audience', 'manage_automation']);
        Role::firstOrCreate(['name' => 'author'])->syncPermissions(['manage_posts']);

        foreach (['trajche.angjelovski@mcash.mk', 'trajce_angelovski@hotmail.com'] as $email) {
            User::where('email', $email)->first()?->assignRole('admin');
        }

        // ── Ad placements ──
        $placements = [
            ['key' => 'home_top',     'name' => 'Homepage (below Trending)', 'format' => 'display',    'description' => 'Between the trending strip and category sections.'],
            ['key' => 'article_mid',  'name' => 'Article — middle',          'format' => 'in-article', 'description' => 'In the middle of every article body.'],
            ['key' => 'article_end',  'name' => 'Article — after content',   'format' => 'display',    'description' => 'Below the article, before related stories.'],
            ['key' => 'category_list','name' => 'Category page',             'format' => 'display',    'description' => 'On category archive pages, above pagination.'],
        ];
        foreach ($placements as $p) {
            AdPlacement::firstOrCreate(['key' => $p['key']], $p);
        }

        $admin = User::where('email', 'trajche.angjelovski@mcash.mk')->first();
        $authorId = $admin?->id;

        // ── Categories ──
        $categories = [
            ['name' => 'Politics',      'color' => '#e33b4e', 'icon' => '🏛️', 'layout' => 'feature'],
            ['name' => 'US News',       'color' => '#3b82f6', 'icon' => '🇺🇸', 'layout' => 'overlay'],
            ['name' => 'World',         'color' => '#10b981', 'icon' => '🌍', 'layout' => 'rows'],
            ['name' => 'Story of Hope', 'color' => '#f472b6', 'icon' => '🕊️', 'layout' => 'feature'],
            ['name' => 'Opinion',       'color' => '#a855f7', 'icon' => '✍️', 'layout' => 'quotes'],
        ];
        $catIds = [];
        foreach ($categories as $i => $c) {
            $cat = Category::updateOrCreate(
                ['slug' => Str::slug($c['name'])],
                array_merge($c, ['sort_order' => $i, 'is_active' => true]),
            );
            $catIds[$cat->slug] = $cat->id;
        }

        // ── Posts (ported from the original static design) ──
        $posts = [
            // Politics
            ['politics', 'Congress Reaches Historic Deal on Sweeping Reform Package', 'Lawmakers from both parties announce a framework agreement that could reshape federal policy for a decade.', '🏛️', 'Michael Reeves', true],
            ['politics', 'Senate Committee Launches Investigation Into Federal Spending Program', 'A bipartisan panel will examine allegations of mismanagement in the multi-billion dollar initiative.', '🏛️', 'Sarah Mitchell', false],
            ['politics', 'Governor Signs Controversial Bill Despite Widespread Protests', 'The legislation passed along party lines after weeks of heated debate in the state capitol.', '📜', 'James Carter', false],
            ['politics', 'Former Cabinet Official Announces Surprise Presidential Bid', 'The announcement shakes up an already crowded primary field months before the first debates.', '🎤', 'Elena Rodriguez', false],
            // US News
            ['us-news', 'Historic Flooding Forces Thousands to Evacuate Across Midwest', 'Emergency crews are working around the clock as rivers crest at record levels.', '🌊', 'David Chen', true],
            ['us-news', 'Major City Announces Sweeping Reform of Public Transit System', 'The billion-dollar overhaul promises faster commutes and expanded coverage by 2028.', '🚇', 'Amanda Foster', false],
            ['us-news', 'Supreme Court Agrees to Hear Case That Could Redefine Federal Authority', 'Legal scholars call it the most consequential case on the docket this term.', '⚖️', 'Robert Hayes', false],
            // World
            ['world', 'Global Summit Ends With Surprise Trade Agreement Between Rival Powers', 'Diplomats stunned observers as two long-time adversaries signed a sweeping economic pact.', '🌍', 'Marie Dubois', true],
            ['world', 'European Leaders Convene Emergency Summit on Energy Crisis', 'Officials seek a unified response as prices surge across the continent.', '⚡', 'Marie Dubois', false],
            ['world', 'Peace Talks Resume After Six-Month Stalemate', 'Diplomats express cautious optimism as both sides return to the negotiating table.', '🕊️', 'Ahmed Hassan', false],
            ['world', 'Archaeologists Uncover Ancient City Beneath Desert Sands', 'The discovery could rewrite the history of an entire civilization.', '🏺', 'Yuki Tanaka', false],
            // Story of Hope
            ['story-of-hope', 'Veteran Completes Cross-Country Walk, Raising Millions for Wounded Heroes', 'After 3,000 miles and 14 states, a heros journey ends with a life-changing donation.', '🎗️', 'Daniel Park', true],
            ['story-of-hope', 'Entire Town Shows Up to Rebuild Elderly Couples Home in One Weekend', 'More than 200 neighbors volunteered after a fire destroyed everything the couple owned.', '🏠', 'Grace Okafor', false],
            ['story-of-hope', 'High School Students Invention Brings Clean Water to Thousands', 'A science-fair project became a real-world solution now used in three countries.', '💧', 'Samuel Ross', false],
            // Opinion
            ['opinion', 'The Quiet Revolution Happening in American Classrooms', 'Why the biggest education story of the decade is going largely unreported.', '🎓', 'Dr. Lisa Wong', true],
            ['opinion', 'We Need to Talk About the Future of Local News', 'As newsrooms shrink, communities lose more than headlines — they lose accountability.', '📰', 'Mark Stevens', false],
            ['opinion', 'What the Data Really Says About the Economy', 'Beyond the headlines, the numbers tell a more complicated story.', '📊', 'Paul Nguyen', false],
        ];

        foreach ($posts as $offset => [$catSlug, $title, $excerpt, $icon, $authorName, $featured]) {
            Post::updateOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'body' => "<p>{$excerpt}</p><p>This is placeholder article body content. Replace it with the full story from the admin panel. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>",
                    'category_id' => $catIds[$catSlug],
                    'author_id' => $authorId,
                    'image_icon' => $icon,
                    'status' => 'published',
                    'is_featured' => $featured,
                    'published_at' => now()->subHours($offset + 1),
                    'views' => random_int(500, 25000),
                ],
            );
        }

        // ── Products (Patriot Shop) ──
        $products = [
            ['Patriot Eagle Embroidered Cap', 24.99, '🧢', 'Best Seller'],
            ['"We The People" Insulated Steel Mug', 19.99, '☕', 'New'],
            ['Vintage American Flag T-Shirt', 29.99, '👕', null],
            ['Liberty Eagle Lapel Pin Set (3-Pack)', 12.99, '🦅', null],
            ['Freedom Paracord Bracelet', 14.99, '⭐', 'New'],
            ['Constitution Pocket Edition + Case', 16.99, '📜', 'Best Seller'],
            ['Patriot LED Tactical Flashlight', 34.99, '🔦', null],
            ['Stars & Stripes Car Decal Pack', 9.99, '🚗', null],
        ];
        foreach ($products as $i => [$name, $price, $icon, $tag]) {
            Product::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => 'High-quality patriot gear. ' . $name . ' — proudly designed for those who love their country.',
                    'price' => $price,
                    'image_icon' => $icon,
                    'tag' => $tag,
                    'stock' => 100,
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
