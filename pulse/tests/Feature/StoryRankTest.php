<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IngestedItem;
use App\Models\IngestSource;
use App\Models\Post;
use App\Models\Setting;
use App\Services\ArticleFetcher;
use App\Services\Deduplicator;
use App\Services\FeedReader;
use App\Services\ImageService;
use App\Services\IngestService;
use App\Services\SeoBooster;
use App\Services\SeoOptimizer;
use App\Support\StoryRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ranking: final score (written article, final section) + coverage + verified
 * − age; only breaking news publishes at ingest; each slot goes to the best
 * story; strong single-source stories have a guarded fallback. All AI faked.
 */
class StoryRankTest extends TestCase
{
    use RefreshDatabase, Concerns\ResearchFixtures;

    /** @var array<string,Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::now('America/New_York')->startOfDay()->setTime(12, 0)->utc());
        foreach (['politics' => 'Politics', 'us-news' => 'US News', 'world' => 'World', 'story-of-hope' => 'Story of Hope', 'opinion' => 'Opinion'] as $slug => $name) {
            $this->cat[$slug] = Category::create(['name' => $name, 'slug' => $slug]);
        }
        Setting::put('daily_publish_cap', '15');
        Setting::put('min_sources_to_publish', '1');
        Setting::put('min_publish_words', '100');
    }

    private function body(string $seed): string
    {
        $out = '';
        for ($i = 1; $i <= 8; $i++) {
            $out .= "<p>Officials in {$seed} district {$i} confirmed on Friday that crews had finished work order {$seed}{$i} and reopened the route by noon.</p>";
        }

        return $out;
    }

    private function draft(string $slug, int $score, string $seed, array $attrs = []): Post
    {
        return Post::create($attrs + [
            'title' => "Crews finish {$seed} repairs ahead of schedule", 'slug' => "{$seed}-" . uniqid(), 'body' => $this->body($seed),
            'category_id' => $this->cat[$slug]->id, 'status' => 'draft', 'queued_at' => now()->subMinutes(30),
            'source_url' => "https://example.com/{$seed}", 'source_published_at' => now()->subMinutes(30),
            'editorial_score' => $score, 'push_notified_at' => now(), 'social_posted_at' => now(),
        ]);
    }

    public function test_rank_formula_and_explanation(): void
    {
        $post = $this->draft('politics', 78, 'alpha', [
            'coverage_outlets' => ['foxnews.com', 'nypost.com', 'resistthemainstream.com'], 'review_status' => 'ready',
        ]);
        $this->assertSame(10, StoryRank::coverageBoost($post));
        $this->assertSame('score 78 + coverage 10 + verified 5 − age 6.0 = 87.0', StoryRank::explain($post, 'politics'));

        $post->coverage_outlets = ['a.com', 'b.com', 'c.com', 'd.com', 'e.com', 'f.com'];
        $this->assertSame(15, StoryRank::coverageBoost($post));                 // capped

        $hope = $this->draft('story-of-hope', 70, 'hope', ['source_published_at' => now()->subHours(2)]);
        $this->assertEqualsWithDelta(8.0, StoryRank::agePenalty($hope, 'story-of-hope'), 0.01);   // 4/h
        $this->assertEqualsWithDelta(24.0, StoryRank::agePenalty($hope, 'politics'), 0.01);       // 12/h
    }

    public function test_coverage_counts_each_other_outlet_once(): void
    {
        $post = $this->draft('politics', 70, 'bravo', ['source_url' => 'https://www.foxnews.com/politics/a', 'coverage_outlets' => ['foxnews.com']]);
        $item = IngestedItem::create(['ingest_source_id' => IngestSource::create(['name' => 'F', 'feed_url' => 'https://f.test/x', 'is_active' => true, 'category_id' => $this->cat['politics']->id])->id,
            'guid' => 'g1', 'source_url' => 'https://www.foxnews.com/politics/a', 'title' => 'T', 'status' => 'processed', 'post_id' => $post->id]);

        $count = new \ReflectionMethod(IngestService::class, 'countCoverage');
        $count->setAccessible(true);
        $svc = app(IngestService::class);
        $count->invoke($svc, $item, 'https://nypost.com/2026/10/19/a');
        $count->invoke($svc, $item, 'https://nypost.com/2026/10/19/a-again');   // same outlet
        $count->invoke($svc, $item, 'https://www.foxnews.com/us/a');           // our own outlet

        $this->assertSame(['foxnews.com', 'nypost.com'], $post->fresh()->coverage_outlets);
        $this->assertSame(5, StoryRank::coverageBoost($post->fresh()));
    }

    /** Run one feed item through ingest; $scores are returned by successive score calls. */
    private function ingest(string $feedSection, array $scores, array $ai = [], ?string $sourceText = null): IngestedItem
    {
        $sourceText ??= str_repeat('Crews reopened the route. ', 60);
        Setting::put('openai_key', 'test');
        $source = IngestSource::create(['name' => 'Feed', 'feed_url' => 'https://feed.test/x', 'category_id' => $this->cat[$feedSection]->id,
            'is_active' => true, 'auto_publish' => true, 'fetch_images' => false, 'ai_image' => false, 'max_items' => 5]);
        $link = 'https://outlet.test/' . uniqid();
        $feed = \Mockery::mock(FeedReader::class);
        $feed->shouldReceive('read')->andReturn([['guid' => $link, 'link' => $link, 'title' => 'Story',
            'summary' => 'Summary.', 'image' => null, 'published_at' => now()->subMinutes(20)]]);
        $this->app->instance(FeedReader::class, $feed);
        $fetcher = \Mockery::mock(ArticleFetcher::class);
        $fetcher->shouldReceive('extract')->andReturn(['text' => $sourceText, 'image' => null, 'published_at' => null]);
        $this->app->instance(ArticleFetcher::class, $fetcher);
        $dedup = \Mockery::mock(Deduplicator::class);
        $dedup->shouldReceive('findDuplicate')->andReturn(null);
        $this->app->instance(Deduplicator::class, $dedup);
        $seo = \Mockery::mock(SeoOptimizer::class);
        $seo->shouldReceive('optimizePost')->andReturn([]);
        $this->app->instance(SeoOptimizer::class, $seo);
        $booster = \Mockery::mock(SeoBooster::class);
        $booster->shouldReceive('boost')->andReturn(90);
        $this->app->instance(SeoBooster::class, $booster);

        $this->scorePrompts = [];
        Http::fake(['api.openai.com/*' => function ($request) use (&$scores, $ai, $feedSection) {
            $schema = $request['response_format']['json_schema']['name'] ?? null;
            if ($schema === null) {   // the one-shot "expand" call: no expansion
                return Http::response(['choices' => [['message' => ['content' => '']]]]);
            }
            if ($schema === 'editorial_score') {
                $this->scorePrompts[] = $request['messages'][0]['content'];
                $next = array_shift($scores);

                return ($next === 'fail' || $next === null) ? Http::response([], 500)   // null: a retry after a failure
                    : Http::response(['choices' => [['message' => ['content' => json_encode($next)]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($ai + [
                'title' => 'Crews reopen the route after storm repairs', 'excerpt' => 'The route reopened.', 'social_text' => 'Open.',
                'body' => $this->body('ingest'), 'category' => $feedSection, 'tags' => [], 'faqs' => [], 'is_breaking' => false,
                'is_top_story' => false, 'is_trending' => false, 'focus_keyword' => 'route', 'meta_title' => 'Route reopens', 'meta_description' => 'The route reopened after repairs.',
            ])]]]]);
        }]);

        app(IngestService::class)->runSource($source);

        return IngestedItem::latest('id')->firstOrFail();
    }

    private array $scorePrompts = [];

    public function test_a_strong_regular_story_waits_for_its_slot(): void
    {
        $regular = $this->ingest('politics', [['score' => 95, 'urgent' => false, 'reason' => 'Big.'], ['score' => 92, 'urgent' => false, 'reason' => 'Big.']]);
        $post = Post::findOrFail($regular->post_id);
        $this->assertSame('draft', $post->status);
        $this->assertNotNull($post->queued_at);
        $this->assertSame(92, $post->editorial_score);                          // the FINAL score ranks it
    }

    public function test_breaking_news_goes_to_the_research_fast_lane_not_straight_live(): void
    {
        $breaking = $this->ingest('politics', [['score' => 95, 'urgent' => true, 'reason' => 'Breaking.'], ['score' => 96, 'urgent' => true, 'reason' => 'Breaking.']]);
        $post = Post::findOrFail($breaking->post_id);
        $this->assertSame('draft', $post->status);
        $this->assertNotNull($post->queued_at);
        $this->assertTrue($post->is_breaking);
    }

    public function test_final_score_judges_the_article_in_its_final_section(): void
    {
        // World feed, but the writer files it under US News; US News bar is 65.
        $item = $this->ingest('world', [['score' => 70, 'urgent' => false, 'reason' => 'Ok.'], ['score' => 50, 'urgent' => false, 'reason' => 'Routine.']],
            ['category' => 'us-news']);
        $this->assertStringContainsString('filling the World section', $this->scorePrompts[0]);
        $this->assertStringContainsString('filling the US News section', $this->scorePrompts[1]);
        $this->assertStringContainsString('Final score 50 is below the us-news bar (65)', $item->error);
        $this->assertNull(Post::findOrFail($item->post_id)->queued_at);              // never queued
    }

    public function test_a_failed_final_score_keeps_the_screen_score(): void
    {
        $item = $this->ingest('politics', [['score' => 72, 'urgent' => false, 'reason' => 'Ok.'], 'fail']);
        $post = Post::findOrFail($item->post_id);
        $this->assertSame(72, $post->editorial_score);                          // not a free 100
        $this->assertNotNull($post->queued_at);
    }

    public function test_ingest_and_publishing_use_one_length_rule(): void
    {
        Setting::put('min_publish_words', '500');
        $this->assertSame(350, \App\Support\PublishGate::minWords('breaking'));
        $this->assertSame(400, \App\Support\PublishGate::minWords('crime'));
        $this->assertSame(500, \App\Support\PublishGate::minWords(null));
    }

    public function test_a_short_first_rewrite_of_a_real_source_is_queued_not_dropped_or_published(): void
    {
        $short = '<p>Officials reopened the route on Friday after storm repairs.</p><p>Crews cleared debris overnight.</p>';
        $source = str_repeat('County crews reopened the storm-damaged route on Friday after overnight repairs. ', 50);   // ~550 words

        // Breaking story: short rewrite must NOT publish from ingest, but it is queued for the fast lane retries.
        $item = $this->ingest('world', [['score' => 81, 'urgent' => true, 'reason' => 'Big.'], ['score' => 81, 'urgent' => true, 'reason' => 'Big.']],
            ['body' => $short], $source);
        $post = Post::findOrFail($item->post_id);
        $this->assertSame('draft', $post->status);
        $this->assertNotNull($post->queued_at);
        $this->assertTrue($post->is_breaking);
        $this->assertStringContainsString('Short first rewrite', $item->error);
    }

    public function test_a_short_rewrite_of_a_thin_source_is_not_queued(): void
    {
        $item = $this->ingest('world', [['score' => 81, 'urgent' => false, 'reason' => 'Ok.']],
            ['body' => '<p>Officials reopened the route on Friday.</p>'], str_repeat('Short source. ', 50));   // 100 words
        $this->assertNull(Post::findOrFail($item->post_id)->queued_at);
    }

    public function test_needs_research_drafts_are_retried_at_most_three_times(): void
    {
        $post = $this->draft('world', 81, 'retry', ['review_status' => 'needs_research', 'web_researched_at' => now()->subMinutes(25)]);
        $this->assertTrue(\App\Services\StorySynthesizer::retryDue($post, 20));
        $this->assertFalse(\App\Services\StorySynthesizer::retryDue($post, 30));     // regular queue waits 30 min
        for ($i = 0; $i < 3; $i++) {
            \App\Services\StorySynthesizer::noteRetry($post);
        }
        $this->assertFalse(\App\Services\StorySynthesizer::retryDue($post, 20));     // capped
        $ready = $this->draft('world', 81, 'ready', ['review_status' => 'needs_rewrite', 'web_researched_at' => now()->subHour()]);
        $this->assertFalse(\App\Services\StorySynthesizer::retryDue($ready, 20));    // only "needs research"
    }

    private function fakePublishing(): void
    {
        $images = \Mockery::mock(ImageService::class);
        $images->shouldReceive('generate')->andReturn(null);
        $this->app->instance(ImageService::class, $images);
        $booster = \Mockery::mock(SeoBooster::class);
        $booster->shouldReceive('boost')->andReturn(90);
        $this->app->instance(SeoBooster::class, $booster);
    }

    public function test_an_unresearched_story_never_publishes_however_strong(): void
    {
        $this->fakePublishing();
        $strong = $this->draft('politics', 99, 'strong');                       // single-source, never researched

        $this->artisan('posts:promote-queued', ['--per-run' => 1])->assertSuccessful();
        $this->assertSame('draft', $strong->fresh()->status);

        // Even "ready" without a research package is refused by the final check.
        $strong->forceFill(['review_status' => 'ready', 'sources' => [['url' => 'https://a.test/x'], ['url' => 'https://b.test/y']]])->saveQuietly();
        $this->artisan('posts:promote-queued', ['--per-run' => 1])->expectsOutputToContain('No research package')->assertSuccessful();
        $this->assertSame('draft', $strong->fresh()->status);
    }

    public function test_the_best_ranked_story_wins_the_slot_not_the_first_one(): void
    {
        $this->fakePublishing();
        $first = $this->draft('politics', 81, 'first', ['review_status' => 'ready', 'queued_at' => now()->subMinutes(40), 'research_package' => $this->researchPackage()]);
        $better = $this->draft('politics', 80, 'better', ['review_status' => 'ready', 'research_package' => $this->researchPackage(), 'coverage_outlets' => ['a.com', 'b.com', 'c.com'],
            'queued_at' => now()->subMinutes(5), 'source_published_at' => now()->subMinutes(5)]);

        $this->artisan('posts:promote-queued', ['--per-run' => 1])
            ->expectsOutputToContain('coverage 10')->assertSuccessful();
        $this->assertSame('published', $better->fresh()->status);
        $this->assertSame('draft', $first->fresh()->status);
    }
}
