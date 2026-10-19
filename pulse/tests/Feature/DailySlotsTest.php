<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageAiSettings;
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
use App\Services\Rewriter;
use App\Services\SeoBooster;
use App\Services\SeoOptimizer;
use App\Support\DailyCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The daily plan: 15 news posts split into fixed section slots (Politics 4,
 * US News 4, World 3, Story of Hope 2) + 2 breaking, each section scored on its
 * own scale against its own bar, leftovers released at 20:00 New York, and
 * Opinion counted separately. All AI/HTTP traffic is faked.
 */
class DailySlotsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->at(12);
        foreach (['politics' => 'Politics', 'us-news' => 'US News', 'world' => 'World', 'story-of-hope' => 'Story of Hope', 'opinion' => 'Opinion'] as $slug => $name) {
            $this->cat[$slug] = Category::create(['name' => $name, 'slug' => $slug]);
        }
        Setting::put('daily_publish_cap', '15');
    }

    /** Freeze the clock at an hour of today's New York day. */
    private function at(int $hour, int $minute = 0): void
    {
        Carbon::setTestNow(Carbon::now('America/New_York')->startOfDay()->setTime($hour, $minute)->utc());
    }

    private function published(string $slug, int $n = 1, bool $breaking = false): void
    {
        for ($i = 0; $i < $n; $i++) {
            Post::create([
                'title' => "{$slug} story " . uniqid(), 'slug' => "{$slug}-" . uniqid(), 'body' => '<p>Report.</p>',
                'category_id' => $this->cat[$slug]->id, 'status' => 'published', 'published_at' => now()->subMinutes(5),
                'is_breaking' => $breaking, 'push_notified_at' => now(), 'social_posted_at' => now(),
            ]);
        }
    }

    public function test_defaults_are_fifteen_split_by_section_with_section_bars(): void
    {
        $this->assertSame(['politics' => 4, 'us-news' => 4, 'world' => 3, 'story-of-hope' => 2], DailyCap::sectionSlots());
        $this->assertSame(15, array_sum(DailyCap::sectionSlots()) + DailyCap::reserve());
        $this->assertSame(65, DailyCap::scoreBar('politics'));
        $this->assertSame(50, DailyCap::scoreBar('world'));
        $this->assertSame(45, DailyCap::scoreBar('story-of-hope'));
        Setting::put('publish_score_threshold', '70');
        $this->assertSame(70, DailyCap::scoreBar('sports'));          // no own bar → fallback
    }

    public function test_a_full_section_cannot_take_another_sections_slot_before_release(): void
    {
        $this->published('politics', 4);
        $this->assertSame(0, DailyCap::remainingFor('politics'));
        $this->assertSame(3, DailyCap::remainingFor('world'));
        $this->assertSame(2, DailyCap::remainingFor('story-of-hope'));
        $this->assertGreaterThan(0, DailyCap::remainingFor('politics', breaking: true));   // breaking uses any slot
    }

    public function test_breaking_slots_are_held_then_released_in_the_evening(): void
    {
        $this->published('politics', 4);
        $this->published('us-news', 4);
        $this->published('world', 3);
        $this->published('story-of-hope', 2);
        $this->assertSame(0, DailyCap::remaining());
        $this->assertSame(2, DailyCap::remaining(breaking: true));

        $this->at(20, 30);
        $this->assertSame(2, DailyCap::remaining());                   // released to any section
        $this->assertSame(2, DailyCap::remainingFor('politics'));
    }

    public function test_unused_section_slots_open_to_every_section_at_release_time(): void
    {
        $this->published('politics', 4);
        $this->assertSame(0, DailyCap::remainingFor('politics'));
        $this->at(20, 0);
        $this->assertSame(15 - 4, DailyCap::remainingFor('politics'));
        $this->assertSame([], DailyCap::unfilledSlots());
    }

    public function test_opinion_is_counted_separately_from_news(): void
    {
        $this->published('opinion');
        $this->assertSame(0, DailyCap::publishedToday());
        $this->assertSame(0, DailyCap::opinionRemaining());
        $this->assertNull(DailyCap::lastPublishedAt());                  // a column doesn't delay news pacing
        Setting::put('opinion_daily_count', '2');
        $this->assertSame(1, DailyCap::opinionRemaining());
    }

    public function test_each_section_is_scored_on_its_own_scale(): void
    {
        Setting::put('openai_key', 'test');
        $prompts = [];
        Http::fake(['api.openai.com/*' => function ($request) use (&$prompts) {
            $prompts[] = $request['messages'][0]['content'];
            return Http::response(['choices' => [['message' => ['content' => json_encode(['score' => 70, 'urgent' => false, 'reason' => 'ok'])]]]]);
        }]);
        $writer = app(Rewriter::class);
        $writer->score('Egypt ready to defend Saudi Arabia', 'Escalation.', 'world');
        $writer->score('Firefighters save church', 'Rescue.', 'story-of-hope');
        $writer->score('Senate vote', 'Vote.', null);

        $this->assertStringContainsString('filling the World section', $prompts[0]);
        $this->assertStringContainsString('WHETHER OR NOT there is a US angle', $prompts[0]);
        $this->assertStringContainsString('filling the Story of Hope section', $prompts[1]);
        $this->assertStringContainsString('political first', $prompts[2]);   // unknown section → original rubric
    }

    private function ingestScore(string $slug, int $score): IngestedItem
    {
        Setting::put('openai_key', 'test');
        $source = IngestSource::create(['name' => "{$slug} feed", 'feed_url' => "https://example.com/{$slug}.xml", 'category_id' => $this->cat[$slug]->id,
            'is_active' => true, 'auto_publish' => true, 'fetch_images' => false, 'ai_image' => false, 'max_items' => 5]);
        $feed = \Mockery::mock(FeedReader::class);
        $feed->shouldReceive('read')->andReturn([['guid' => "https://example.com/{$slug}/a", 'link' => "https://example.com/{$slug}/a",
            'title' => "A {$slug} story", 'summary' => 'Summary.', 'image' => null, 'published_at' => now()->subMinutes(20)]]);
        $this->app->instance(FeedReader::class, $feed);
        $dedup = \Mockery::mock(Deduplicator::class);
        $dedup->shouldReceive('findDuplicate')->andReturn(null);
        $this->app->instance(Deduplicator::class, $dedup);
        $fetcher = \Mockery::mock(ArticleFetcher::class);
        $fetcher->shouldReceive('extract')->andReturn(['text' => null, 'image' => null, 'published_at' => null]);
        $this->app->instance(ArticleFetcher::class, $fetcher);
        $seo = \Mockery::mock(SeoOptimizer::class);
        $seo->shouldReceive('optimizePost')->andReturn([]);
        $this->app->instance(SeoOptimizer::class, $seo);
        Http::fake(['api.openai.com/*' => function ($request) use ($score) {
            $data = $request['response_format']['json_schema']['name'] === 'editorial_score'
                ? ['score' => $score, 'urgent' => false, 'reason' => 'Scored.']
                : ['title' => 'T', 'excerpt' => 'E', 'social_text' => 'S', 'body' => '<p>Body.</p>', 'category' => 'us-news', 'tags' => [], 'faqs' => [],
                    'is_breaking' => false, 'is_top_story' => false, 'is_trending' => false, 'focus_keyword' => 'k', 'meta_title' => 'M', 'meta_description' => 'D'];

            return Http::response(['choices' => [['message' => ['content' => json_encode($data)]]]]);
        }]);

        app(IngestService::class)->runSource($source);

        return IngestedItem::where('ingest_source_id', $source->id)->firstOrFail();
    }

    public function test_ingest_applies_each_sections_own_bar(): void
    {
        $this->assertSame('skipped', $this->ingestScore('politics', 55)->status);   // politics bar 65
        $world = $this->ingestScore('world', 55);                                   // world bar 50
        $this->assertNotSame('skipped', $world->status);
        $this->assertNotNull($world->post_id);
    }

    private function queued(string $slug, int $score, string $seed): Post
    {
        $body = '';
        for ($i = 1; $i <= 8; $i++) {
            $body .= "<p>Officials in {$seed} district {$i} confirmed on Friday that crews had finished work order {$seed}{$i} and reopened the route by noon.</p>";
        }

        return Post::create([
            'title' => "Crews finish {$seed} repairs ahead of schedule", 'slug' => "{$seed}-" . uniqid(), 'body' => $body,
            'category_id' => $this->cat[$slug]->id, 'status' => 'draft', 'queued_at' => now(), 'source_url' => "https://example.com/{$seed}",
            'editorial_score' => $score, 'review_status' => 'ready',
            'sources' => [['name' => 'AP', 'url' => "https://apnews.com/{$seed}"], ['name' => 'Reuters', 'url' => "https://reuters.com/{$seed}"]],
            'push_notified_at' => now(), 'social_posted_at' => now(),
        ]);
    }

    public function test_queue_rotates_sections_instead_of_always_taking_the_top_score(): void
    {
        Setting::put('min_sources_to_publish', '2');
        Setting::put('min_publish_words', '100');
        $images = \Mockery::mock(ImageService::class);
        $images->shouldReceive('generate')->andReturn(null);
        $this->app->instance(ImageService::class, $images);
        $booster = \Mockery::mock(SeoBooster::class);
        $booster->shouldReceive('boost')->andReturn(90);
        $this->app->instance(SeoBooster::class, $booster);

        $politicsA = $this->queued('politics', 95, 'alpha');
        $politicsB = $this->queued('politics', 94, 'bravo');
        $world = $this->queued('world', 60, 'charlie');

        $this->artisan('posts:promote-queued', ['--per-run' => 1])->assertSuccessful();
        $this->assertSame('published', $politicsA->fresh()->status);          // tie on open share → best score

        $this->at(14);                                                        // past the pacing gap
        $this->artisan('posts:promote-queued', ['--per-run' => 1])->assertSuccessful();
        $this->assertSame('published', $world->fresh()->status);              // World is emptier now
        $this->assertSame('draft', $politicsB->fresh()->status);
    }

    public function test_settings_page_shows_and_saves_the_section_plan(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $this->actingAs($admin)->get(ManageAiSettings::getUrl())->assertOk()
            ->assertSee('Daily slots by section')->assertSee('Story of Hope — posts/day');

        Livewire::actingAs($admin)->test(ManageAiSettings::class)
            ->assertSet('data.category_quotas.world', 3)
            ->set('data.category_quotas.world', 4)
            ->set('data.section_score_bars.world', 55)
            ->call('save');

        $this->assertSame(4, DailyCap::sectionSlots()['world']);
        $this->assertSame(55, DailyCap::scoreBar('world'));
        $this->assertSame(4, DailyCap::sectionSlots()['politics']);
    }
}
