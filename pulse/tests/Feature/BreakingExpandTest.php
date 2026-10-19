<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Services\ArticleFetcher;
use App\Services\BreakingPublisher;
use App\Services\FactChecker;
use App\Services\ImageService;
use App\Services\Rewriter;
use App\Services\SeoBooster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A fact-checked breaking story that is only too short gets ONE expand pass from
 * its own verified sources, is re-checked, and publishes only if it clears.
 */
class BreakingExpandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Category::create(['name' => 'US News', 'slug' => 'us-news']);
        Category::create(['name' => 'Opinion', 'slug' => 'opinion']);
        Setting::put('min_publish_words', '500');                 // breaking floor = 350

        $fetcher = \Mockery::mock(ArticleFetcher::class);
        $fetcher->shouldReceive('extract')->andReturn(['text' => str_repeat('Police said the Guard member was shot near Farragut Square on Monday. ', 30), 'title' => 'Report', 'image' => null]);
        $this->app->instance(ArticleFetcher::class, $fetcher);
        $images = \Mockery::mock(ImageService::class);
        $images->shouldReceive('generate')->andReturn(null);
        $this->app->instance(ImageService::class, $images);
        $booster = \Mockery::mock(SeoBooster::class);
        $booster->shouldReceive('boost')->andReturn(90);
        $this->app->instance(SeoBooster::class, $booster);
    }

    private function body(int $paragraphs, string $seed = 'a'): string
    {
        $out = '';
        for ($i = 1; $i <= $paragraphs; $i++) {
            $out .= "<p>Police confirmed detail {$seed}{$i} about the shooting near Farragut Square, where officers secured block {$i} and interviewed witnesses on Monday.</p>";
        }

        return $out;
    }

    private function story(): Post
    {
        return Post::create([
            'title' => 'Police say National Guard member was shot in Washington, DC', 'slug' => 'guard-' . uniqid(),
            'body' => $this->body(12), 'category_id' => Category::where('slug', 'us-news')->value('id'),   // ~250 words
            'status' => 'draft', 'is_breaking' => true, 'queued_at' => now(), 'source_url' => 'https://www.foxnews.com/us/guard',
            'sources' => [['name' => 'Fox News', 'url' => 'https://www.foxnews.com/us/guard'], ['name' => 'AP', 'url' => 'https://apnews.com/guard']],
            'review_status' => 'ready', 'web_researched_at' => now(), 'story_type' => 'crime',
            'fact_check' => ['verdict' => 'ready', 'claims_checked' => 13, 'unverified' => [], 'issues' => []],
            'push_notified_at' => now(), 'social_posted_at' => now(),
        ]);
    }

    private function fakeWriterAndChecker(string $expandedBody, string $verdict): void
    {
        $writer = \Mockery::mock(Rewriter::class)->makePartial();
        $writer->shouldReceive('writeArticle')->once()->withArgs(function ($ai, $mode, $brief) {
            $this->assertSame('fix', $mode);
            $this->assertStringContainsString('[MUST FIX — length]', $brief['feedback']);
            $this->assertStringContainsString('ONLY', $brief['feedback']);
            $this->assertCount(2, $brief['sources']);                     // the story's own sources, no new research
            return true;
        })->andReturn(['headline' => 'Police say National Guard member was shot in Washington, DC', 'body' => $expandedBody,
            'excerpt' => 'E', 'meta_title' => 'M', 'meta_description' => 'D', 'story_type' => 'crime', 'takeaways' => []]);
        $this->app->instance(Rewriter::class, $writer);

        $checker = \Mockery::mock(FactChecker::class);
        $checker->shouldReceive('check')->andReturn(['verdict' => $verdict, 'claims_checked' => 14, 'issues' => [], 'unverified' => [],
            'critical' => 0, 'source_conflicts' => [], 'source_support' => [], 'sensitive' => false, 'summary' => '']);
        $this->app->instance(FactChecker::class, $checker);
    }

    public function test_a_short_verified_breaking_story_is_expanded_rechecked_and_published(): void
    {
        $post = $this->story();
        $this->fakeWriterAndChecker($this->body(20, 'b'), 'ready');       // ~420 words

        $this->assertSame('published', app(BreakingPublisher::class)->attempt($post));
        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertGreaterThanOrEqual(350, str_word_count(strip_tags($post->body)));
        $this->assertStringContainsString('detail b20', $post->body);
    }

    public function test_the_verified_version_is_kept_when_the_expansion_fails_and_it_runs_once(): void
    {
        $post = $this->story();
        $original = $post->body;
        $this->fakeWriterAndChecker($this->body(20, 'c'), 'rejected');   // a verdict no fix pass can repair

        $publisher = app(BreakingPublisher::class);
        $this->assertStringStartsWith('held:', $publisher->attempt($post));
        $this->assertStringStartsWith('held:', $publisher->attempt($post->refresh()));   // no second expand (writer once)
        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame($original, $post->body);
        $this->assertSame('ready', $post->review_status);
    }

    public function test_a_still_short_expansion_is_not_used(): void
    {
        $post = $this->story();
        $original = $post->body;
        $this->fakeWriterAndChecker($this->body(14, 'd'), 'ready');       // ~290 words, still under 350

        $this->assertStringStartsWith('held:', app(BreakingPublisher::class)->attempt($post));
        $this->assertSame($original, $post->fresh()->body);
    }
}
