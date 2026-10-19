<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IngestedItem;
use App\Models\IngestSource;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\ArticleFetcher;
use App\Services\Deduplicator;
use App\Services\FeedReader;
use App\Services\IngestService;
use App\Services\Rewriter;
use App\Services\SeoBooster;
use App\Services\SeoOptimizer;
use App\Support\ArticleSections;
use App\Support\EditorialPolicy;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Key Takeaways, Our Take and a public Sources list are switched OFF by default
 * and enforced in code on every path: writers, saving, the article page, RSS,
 * publish gate, ingest, cleanup. Reporting and in-text attribution survive.
 * All AI/HTTP traffic is faked.
 */
class ArticleSectionsTest extends TestCase
{
    use RefreshDatabase, Concerns\ResearchFixtures;

    private Category $news;

    private Category $opinion;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday New York: inside the publishing window, so a high-scoring regular
        // story publishes on the normal path (no breaking fast lane involved).
        \Illuminate\Support\Carbon::setTestNow(now('America/New_York')->setTime(12, 0)->utc());
        Queue::fake();
        Http::preventStrayRequests();
        $this->news = Category::create(['name' => 'US News', 'slug' => 'us-news']);
        $this->opinion = Category::create(['name' => 'Opinion', 'slug' => 'opinion']);
    }

    private function reporting(int $n = 6): string
    {
        $out = '';
        for ($i = 1; $i <= $n; $i++) {
            $out .= "<p>County officials said on Friday that crew {$i} finished repairs on the river bridge and reopened lane {$i} to traffic.</p>";
        }

        return $out;
    }

    private function makePost(array $attrs = []): Post
    {
        return Post::create($attrs + [
            'title' => 'County reopens river bridge after repairs', 'slug' => 'bridge-' . uniqid(),
            'body' => $this->reporting(), 'status' => 'draft', 'category_id' => $this->news->id,
            'meta_title' => 'Bridge reopens', 'meta_description' => 'The county reopened the bridge after repairs.',
        ]);
    }

    // ── Defaults & detection ────────────────────────────────────────────────

    public function test_all_three_sections_default_to_off(): void
    {
        $this->assertFalse(ArticleSections::takeawaysEnabled());
        $this->assertFalse(ArticleSections::ourTakeEnabled());
        $this->assertFalse(ArticleSections::sourcesEnabled());
        $this->assertSame([], ArticleSections::takeaways(['A point.']));
    }

    public function test_sections_are_removed_with_only_their_own_content(): void
    {
        $r = $this->reporting(3);
        $cases = [
            'our take h2' => $r . '<h2>Our Take</h2><p>We think the county got this right.</p><p>Credit where due.</p>',
            'our take h3 + later heading' => $r . '<h3>Our View</h3><p>We think so.</p><h2>Next steps</h2><p>KEEP inspections resume Monday.</p>',
            'bold lead' => $r . '<p><strong>Our Take:</strong> We think the county got this right.</p>',
            'takeaways list' => '<h2>Key Takeaways</h2><ul><li>Bridge open.</li><li>Lanes restored.</li></ul>' . $r,
            'emoji takeaways' => '<p><strong>📌 Key Points</strong></p><ul><li>Bridge open.</li></ul>' . $r,
            'sources list' => $r . '<h2>Sources</h2><ul><li><a href="https://apnews.com/a">AP</a></li></ul>',
            'further reading' => $r . '<h4>Further Reading:</h4><ol><li>AP</li></ol>',
        ];
        foreach ($cases as $name => $body) {
            $clean = ArticleSections::strip($body);
            $this->assertSame([], ArticleSections::found($clean), $name);
            $this->assertStringContainsString('crew 3 finished repairs', $clean, $name);
            $this->assertStringNotContainsString('We think', $clean, $name);
            $this->assertStringNotContainsString('Bridge open', $clean, $name);
        }
        $this->assertStringContainsString('KEEP inspections resume Monday', ArticleSections::strip($cases['our take h3 + later heading']));
    }

    public function test_markdown_bodies_are_handled(): void
    {
        $md = "County crews reopened the bridge.\n\n## Key Takeaways\n- Bridge open\n- Lanes restored\n\nInspections resume Monday.\n\n## Our Take\nWe think it went well.";
        $clean = ArticleSections::strip($md);
        $this->assertSame("County crews reopened the bridge.\n\nInspections resume Monday.", $clean);
    }

    public function test_ambiguous_sections_are_flagged_not_cut(): void
    {
        // Text after a Sources label that is not a list could be reporting (a correction).
        $body = '<p>Keep this reporting.</p><h2>Sources</h2><p>AP</p><p>Keep this correction.</p>';
        $this->assertSame($body, ArticleSections::strip($body));
        $this->assertSame([ArticleSections::SOURCES], ArticleSections::found($body));
        $this->assertSame([ArticleSections::TAKEAWAYS], ArticleSections::found('<p>Report.</p><p><strong>Key takeaways:</strong> the bridge is open.</p>'));
    }

    public function test_ordinary_prose_is_never_a_section(): void
    {
        foreach ([
            '<p>Our take on the vote differs from the mayor\'s, the council member said.</p>',
            '<p>Sources said the vote would happen Monday.</p>', '<h2>Water sources remain contaminated</h2><p>Testing continues.</p>',
            '<p>The key takeaways from the audit were published by the state.</p>', '<h2>Why the bridge closed</h2><p>Cracks were found.</p>',
            '<p><strong>Update (October 19, 2026):</strong> The bridge reopened.</p>',
        ] as $body) {
            $this->assertSame([], ArticleSections::found($body), $body);
            $this->assertSame($body, ArticleSections::strip($body), $body);
        }
    }

    public function test_switches_turn_sections_back_on_and_opinion_columns_keep_our_take(): void
    {
        $body = $this->reporting(2) . '<h2>Our Take</h2><p>We think so.</p>';
        $this->assertSame($body, ArticleSections::strip($body, isOpinion: true));
        Setting::put('show_our_take', '1');
        $this->assertSame($body, ArticleSections::strip($body));
        Setting::put('show_key_takeaways', '1');
        $this->assertSame(['One.'], ArticleSections::takeaways(['One.']));
    }

    // ── Enforcement on save, page, RSS ──────────────────────────────────────

    public function test_saving_clears_takeaways_and_strips_sections_on_every_path(): void
    {
        $post = $this->makePost(['takeaways' => ['A.', 'B.'], 'body' => $this->reporting() . '<h2>Our Take</h2><p>We think so.</p>']);
        $this->assertNull($post->fresh()->takeaways);
        $this->assertStringNotContainsString('Our Take', $post->fresh()->body);

        $post->forceFill(['takeaways' => ['C.'], 'body' => $post->body . '<h2>Key Takeaways</h2><ul><li>X</li></ul>'])->saveQuietly();
        $this->assertNull($post->fresh()->takeaways);
        $this->assertStringNotContainsString('Key Takeaways', $post->fresh()->body);
    }

    public function test_publishing_with_an_unremovable_section_is_blocked(): void
    {
        $post = $this->makePost(['body' => $this->reporting() . '<p><strong>Key takeaways:</strong> the bridge is open.</p>']);
        $post->status = 'published';
        $this->expectException(ValidationException::class);
        $post->save();
    }

    public function test_article_page_and_rss_never_show_disabled_sections_even_for_legacy_rows(): void
    {
        $post = $this->makePost(['status' => 'published', 'published_at' => '2026-01-01 12:00:00', 'excerpt' => 'The county reopened the bridge.']);
        // A legacy/restored row written outside the model.
        DB::table('posts')->where('id', $post->id)->update([
            'takeaways' => json_encode(['TAKEAWAY-ONE should not render.']),
            'body' => $this->reporting() . '<h2>Our Take</h2><p>OPINION-TEXT should not render.</p><h2>Sources</h2><ul><li>AP</li></ul>',
        ]);

        $this->get(route('post.show', $post))->assertOk()
            ->assertDontSee('Key Takeaways')->assertDontSee('TAKEAWAY-ONE')->assertDontSee('OPINION-TEXT')
            ->assertDontSee('>Sources</h', false)->assertSee('crew 6 finished repairs')
            ->assertSee('<link rel="canonical" href="' . route('post.show', $post), false)->assertSee('Bridge reopens');

        $this->get('/rss')->assertOk()->assertDontSee('TAKEAWAY-ONE')->assertSee('The county reopened the bridge.');
    }

    // ── Publish gate & freshness ────────────────────────────────────────────

    public function test_gate_blocks_disabled_sections_opinion_in_news_and_stale_stories(): void
    {
        $bold = new Post(['category_id' => $this->news->id, 'body' => $this->reporting() . '<p><strong>Analysis:</strong> This matters.</p>']);
        $this->assertArrayHasKey('opinion-in-news', PublishGate::ingestFailures($bold));

        $legacy = new Post(['category_id' => $this->news->id, 'body' => $this->reporting() . '<p><strong>Key takeaways:</strong> open.</p>']);
        $this->assertArrayHasKey('disabled-section', PublishGate::originality($legacy));

        $old = new Post(['category_id' => $this->news->id, 'body' => $this->reporting(), 'source_published_at' => now()->subHours(9)]);
        $this->assertArrayHasKey('stale', PublishGate::ingestFailures($old));
        $this->assertArrayHasKey('stale', PublishGate::failures($old));
        $fresh = new Post(['category_id' => $this->news->id, 'body' => $this->reporting(), 'source_published_at' => now()->subHours(2)]);
        $this->assertSame([], PublishGate::ingestFailures($fresh));
    }

    // ── Writers ─────────────────────────────────────────────────────────────

    public function test_policy_prompt_states_the_switches(): void
    {
        $prompt = EditorialPolicy::forPrompt();
        $this->assertStringContainsString('Key Takeaways: OFF', $prompt);
        $this->assertStringContainsString('Our Take section in news: OFF', $prompt);
        $this->assertStringContainsString('Public Sources/References section: OFF', $prompt);
        $this->assertStringContainsString('Always ON: research, fact-checking', $prompt);
    }

    public function test_editorial_writer_is_not_asked_for_takeaways_and_strips_our_take(): void
    {
        $body = str_repeat('<p>County officials confirmed repairs were completed Friday and the bridge reopened after inspectors checked each support and the roadway surface.</p>', 12);
        foreach (['new', 'revision', 'fix'] as $mode) {
            $ai = \Mockery::mock(AiClient::class);
            $ai->shouldReceive('json')->once()->withArgs(function ($stage, $system, $user, $props) {
                $this->assertArrayNotHasKey('takeaways', $props);
                $this->assertStringNotContainsString('- takeaways:', $system);
                $this->assertStringNotContainsString('"Our Take" lines', $system);
                return true;
            })->andReturn([
                'body' => $body . '<h2>Our Take</h2><p>We think the county did well.</p>', 'headline' => 'Bridge open',
                'excerpt' => 'Open Friday.', 'story_type' => 'breaking', 'meta_title' => 'Bridge open',
                'meta_description' => 'Open Friday.', 'takeaways' => ['Should be dropped.'],
            ]);
            $draft = app(Rewriter::class)->writeArticle($ai, $mode, ['title' => 'Bridge open', 'sources' => [['name' => 'County', 'url' => 'https://county.gov/n', 'text' => strip_tags($body)]]]);
            $this->assertSame([], $draft['takeaways'], $mode);
            $this->assertStringNotContainsString('Our Take', $draft['body'], $mode);
        }
    }

    public function test_ingest_writer_omits_takeaways_and_never_gets_the_editorial_perspective(): void
    {
        Setting::put('openai_key', 'test');
        Setting::put('min_publish_words', '50');   // no follow-up "expand" call
        Setting::put('editorial_stance', 'STANCE-MARKER conservative perspective');
        $sent = null;
        Http::fake(['api.openai.com/*' => function ($request) use (&$sent) {
            $sent = $request->data();
            return Http::response(['choices' => [['message' => ['content' => json_encode([
                'title' => 'County reopens river bridge', 'excerpt' => 'It reopened.', 'social_text' => 'Bridge open.',
                'body' => $this->reporting() . '<h2>Our Take</h2><p>We think so.</p>', 'category' => 'us-news', 'tags' => ['Bridges'],
                'takeaways' => ['Dropped.'], 'faqs' => [], 'is_breaking' => false, 'is_top_story' => false, 'is_trending' => false,
                'focus_keyword' => 'river bridge', 'meta_title' => 'River bridge reopens', 'meta_description' => 'The county reopened the river bridge.',
            ])]]]]);
        }]);

        $out = app(Rewriter::class)->rewrite(['title' => 'Bridge', 'link' => 'https://county.gov/n', 'summary' => 'Reopened.', 'full_text' => str_repeat('Reopened. ', 50), 'published_at' => now()->subHour()], 'US News', 'County', [['slug' => 'us-news', 'name' => 'US News']]);

        $schema = $sent['response_format']['json_schema']['schema'];
        $this->assertArrayNotHasKey('takeaways', $schema['properties']);
        $this->assertNotContains('takeaways', $schema['required']);
        $this->assertStringNotContainsString('STANCE-MARKER', json_encode($sent));
        $this->assertStringContainsString('SOURCE PUBLISHED:', $sent['messages'][1]['content']);
        $this->assertSame('County reopens river bridge', $out['title']);   // the AI path ran (not the stub)
        $this->assertSame([], $out['takeaways']);
        $this->assertStringNotContainsString('Our Take', $out['body']);
        $this->assertFalse(ArticleSections::ourTakeEnabled());
    }

    public function test_editorial_perspective_reaches_opinion_columns_only(): void
    {
        Setting::put('editorial_stance', 'STANCE-MARKER');
        $sent = null;
        Http::fake(['api.openai.com/*' => function ($request) use (&$sent) {
            $sent = $request->data();
            return Http::response(['choices' => [['message' => ['content' => json_encode(['title' => 'T', 'excerpt' => 'E', 'body' => '<p>B</p>'])]]]]);
        }]);
        $cmd = app(\App\Console\Commands\OpinionsGenerate::class);
        $write = new \ReflectionMethod($cmd, 'write');
        $write->setAccessible(true);
        try {
            $write->invoke($cmd, 'County reopens bridge', 'It reopened.', 'test');
        } catch (\Throwable) {
            // only the outgoing prompt matters here
        }
        $this->assertNotNull($sent);
        $this->assertStringContainsString('STANCE-MARKER', $sent['messages'][0]['content']);
    }

    // ── Ingest end to end ───────────────────────────────────────────────────

    private function ingest(array $item, ?string $pagePublished, array $ai): IngestedItem
    {
        Setting::put('openai_key', 'test');
        Setting::put('min_sources_to_publish', '1');
        Setting::put('min_publish_words', '50');
        $source = IngestSource::create(['name' => 'County Feed', 'feed_url' => 'https://county.gov/feed', 'category_id' => $this->news->id,
            'is_active' => true, 'auto_publish' => true, 'fetch_images' => false, 'ai_image' => false, 'max_items' => 5]);

        $feed = \Mockery::mock(FeedReader::class);
        $feed->shouldReceive('read')->andReturn([$item + ['guid' => $item['link'], 'summary' => 'Reopened.', 'image' => null]]);
        $this->app->instance(FeedReader::class, $feed);
        $fetcher = \Mockery::mock(ArticleFetcher::class);
        $fetcher->shouldReceive('extract')->andReturn(['text' => str_repeat('County crews reopened the bridge Friday. ', 40), 'image' => null, 'published_at' => $pagePublished]);
        $this->app->instance(ArticleFetcher::class, $fetcher);
        $dedup = \Mockery::mock(Deduplicator::class);
        $dedup->shouldReceive('findDuplicate')->andReturn(null);
        $this->app->instance(Deduplicator::class, $dedup);
        $seo = \Mockery::mock(SeoOptimizer::class);
        $seo->shouldReceive('optimizePost')->andReturn([]);
        $this->app->instance(SeoOptimizer::class, $seo);
        $boost = \Mockery::mock(SeoBooster::class);
        $boost->shouldReceive('boost')->andReturn(90);
        $this->app->instance(SeoBooster::class, $boost);

        Http::fake(['api.openai.com/*' => function ($request) use ($ai) {
            $data = $request['response_format']['json_schema']['name'] === 'editorial_score'
                ? ['score' => 95, 'urgent' => true, 'reason' => 'Major.']   // breaking: the only path that publishes at ingest
                : $ai + ['title' => 'County reopens river bridge after repairs', 'excerpt' => 'The county reopened the bridge.', 'social_text' => 'Bridge open.',
                    'body' => $this->reporting(8), 'category' => 'us-news', 'tags' => [], 'faqs' => [], 'is_breaking' => false,
                    'is_top_story' => false, 'is_trending' => false, 'focus_keyword' => 'river bridge',
                    'meta_title' => 'River bridge reopens', 'meta_description' => 'The county reopened the river bridge after repairs.'];

            return Http::response(['choices' => [['message' => ['content' => json_encode($data)]]]]);
        }]);

        app(IngestService::class)->runSource($source);

        return IngestedItem::latest('id')->firstOrFail();
    }

    public function test_an_ingested_story_is_queued_without_the_disabled_sections(): void
    {
        $record = $this->ingest(['title' => 'Bridge reopens', 'link' => 'https://county.gov/a', 'published_at' => now()->subMinutes(30)], null,
            ['body' => $this->reporting(8) . '<h2>Our Take</h2><p>We think so.</p>', 'takeaways' => ['Dropped.']]);

        $post = Post::findOrFail($record->post_id);
        $this->assertSame('draft', $post->status);          // nothing publishes before research + fact-check
        $this->assertNotNull($post->queued_at);
        $this->assertNull($post->takeaways);
        $this->assertSame([], ArticleSections::found($post->body));
        $this->assertStringNotContainsString('Our Take', $post->body);
        $this->assertEqualsWithDelta(now()->subMinutes(30)->timestamp, $post->source_published_at->timestamp, 5);
    }

    public function test_ingest_holds_a_story_with_an_unremovable_section_instead_of_publishing(): void
    {
        $record = $this->ingest(['title' => 'Bridge reopens', 'link' => 'https://county.gov/b', 'published_at' => now()->subMinutes(30)], null,
            ['body' => $this->reporting(8) . '<p><strong>Key takeaways:</strong> the bridge is open.</p>']);

        $this->assertSame('draft', Post::findOrFail($record->post_id)->status);
    }

    public function test_ingest_uses_the_earliest_date_and_skips_stale_or_undated_stories(): void
    {
        // Feed says 30 min ago (re-dated), the article page says 9 hours ago → stale.
        $stale = $this->ingest(['title' => 'Old bridge story', 'link' => 'https://county.gov/c', 'published_at' => now()->subMinutes(30)], now()->subHours(9)->toIso8601String(), []);
        $this->assertSame('skipped', $stale->status);
        $this->assertStringContainsString('Stale', $stale->error);
        $this->assertNull($stale->post_id);

        $undated = $this->ingest(['title' => 'Undated story', 'link' => 'https://county.gov/d', 'published_at' => null], null, []);
        $this->assertSame('skipped', $undated->status);
        $this->assertStringContainsString('No publish date', $undated->error);
    }

    // ── Cleanup of stored articles ──────────────────────────────────────────

    public function test_cleanup_backs_up_strips_safely_and_rolls_back(): void
    {
        Storage::fake('local');
        $post = $this->makePost(['status' => 'published', 'published_at' => '2026-01-01 12:00:00']);
        $legacyBody = $this->reporting() . '<h2>Our Take</h2><p>We think so.</p>';
        DB::table('posts')->where('id', $post->id)->update(['body' => $legacyBody, 'takeaways' => json_encode(['Old.'])]);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $this->artisan('posts:strip-sections')->expectsOutputToContain('[DRY RUN] 1 article(s)')->assertSuccessful();
        $this->assertSame($legacyBody, DB::table('posts')->where('id', $post->id)->value('body'));

        $this->artisan('posts:strip-sections', ['--apply' => true])->assertSuccessful();
        $after = DB::table('posts')->where('id', $post->id)->first();
        $this->assertStringNotContainsString('Our Take', $after->body);
        $this->assertStringContainsString('crew 6 finished repairs', $after->body);
        $this->assertNull($after->takeaways);
        foreach (['slug', 'published_at', 'updated_at', 'meta_title', 'meta_description', 'featured_image'] as $col) {
            $this->assertSame($before->{$col}, $after->{$col}, $col);
        }

        $file = Storage::disk('local')->files('backups')[0];
        $this->artisan('posts:strip-sections', ['--restore' => $file])->assertSuccessful();
        $this->assertSame($legacyBody, DB::table('posts')->where('id', $post->id)->value('body'));
    }
}
