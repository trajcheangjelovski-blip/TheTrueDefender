<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IngestedItem;
use App\Models\IngestSource;
use App\Models\NewsroomOutbox;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\ArticleFetcher;
use App\Services\Deduplicator;
use App\Services\FactChecker;
use App\Services\FeedReader;
use App\Services\ImageService;
use App\Services\IngestService;
use App\Services\ResearchAnalyst;
use App\Services\Rewriter;
use App\Services\SeoBooster;
use App\Services\SourcePacket;
use App\Support\Publisher;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LocalNewsroomTest extends TestCase
{
    use Concerns\ResearchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.ai.provider' => 'ollama', 'services.ai.token' => str_repeat('x', 32), 'services.ai.url' => 'http://127.0.0.1:8091', 'app.url' => 'https://news.example']);
        Setting::put('openai_key', 'existing-paid-key-must-not-be-used');
        Setting::put('min_publish_words', '100');
    }

    private function candidate(bool $auto = true, string $seed = 'alpha'): Post
    {
        $cat = Category::firstOrCreate(['slug' => 'us-news'], ['name' => 'US News']);
        $source = IngestSource::create(['name' => 'Feed '.$seed, 'feed_url' => 'https://feed.test/'.$seed, 'is_active' => true, 'auto_publish' => $auto, 'category_id' => $cat->id]);
        $body = '';
        for ($i = 1; $i <= 8; $i++) {
            $body .= "<p>Officials in district {$seed}{$i} confirmed that crews completed repair order {$i} and reopened the county road to residents on Friday.</p>";
        }
        $post = Post::create(['title' => 'County reopens roads after flood repairs', 'slug' => $seed, 'body' => $body, 'status' => 'draft',
            'queued_at' => now(), 'category_id' => $cat->id, 'source_url' => 'https://a.test/'.$seed, 'source_published_at' => now(),
            'sources' => [['url' => 'https://a.test/1', 'group' => 0], ['url' => 'https://b.test/2', 'group' => 1], ['url' => 'https://c.test/3', 'group' => 2]],
            'fact_check' => ['verdict' => 'ready', 'critical' => 0, 'unverified' => []], 'review_status' => 'ready',
            'research_package' => $this->researchPackage(), 'event_key' => $seed, 'editorial_score' => 0]);
        IngestedItem::create(['ingest_source_id' => $source->id, 'post_id' => $post->id, 'guid' => $seed, 'title' => $post->title, 'status' => 'processed']);

        return $post;
    }

    public function test_every_local_text_call_uses_the_durable_gateway_and_records_zero_paid_cost(): void
    {
        Http::fake([
            '127.0.0.1:8091/v1/jobs' => Http::response(['id' => str_repeat('a', 64)], 202),
            '127.0.0.1:8091/v1/jobs/*' => Http::response(['state' => 'done', 'result' => ['model' => 'qwen3.5:9b',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"ok":true}']]]]]),
        ]);
        $ai = new AiClient(maxCostUsd: 0);
        $this->assertSame(['ok' => true], $ai->json('check', 'Use supplied evidence', 'Facts', ['ok' => ['type' => 'boolean']]));
        $this->assertSame(0.0, $ai->usage()['estimated_usd']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/jobs') && $request['payload']['model'] === 'qwen3.5:9b');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
    }

    public function test_offline_ai_holds_content_and_never_falls_back_to_a_saved_paid_key(): void
    {
        Http::fake(['127.0.0.1:8091/*' => Http::response([], 503)]);
        $ai = new AiClient;
        $this->assertNull($ai->json('check', 'System', 'User', ['ok' => ['type' => 'boolean']]));
        $this->assertNotNull($ai->lastError());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
    }

    public function test_images_and_embeddings_do_not_call_paid_services_in_local_mode(): void
    {
        Http::fake();
        $this->assertNull(app(ImageService::class)->generate('An illustration'));
        $this->assertNull(app(ImageService::class)->storeFromUrl('https://unlicensed.example/photo.jpg'));
        $this->assertNull(app(Deduplicator::class)->embed('A story'));
        Http::assertNothingSent();
    }

    public function test_discovery_is_durable_and_does_not_require_ai_or_auto_publish(): void
    {
        $source = IngestSource::create(['name' => 'Feed', 'feed_url' => 'https://feed.test/rss', 'is_active' => true, 'auto_publish' => false, 'max_items' => 5]);
        $this->mock(FeedReader::class)->shouldReceive('read')->andReturn([['title' => 'County bridge reopens', 'link' => 'https://county.gov/news', 'published_at' => now()]]);
        $this->mock(ArticleFetcher::class)->shouldReceive('extract')->andReturn(['text' => str_repeat('County crews restored access. ', 50), 'published_at' => now()->toIso8601String()]);
        Http::fake();
        app(IngestService::class)->runSource($source);
        app(IngestService::class)->runSource($source);
        $this->assertSame(1, Post::count());
        $post = Post::first();
        $this->assertSame('', $post->body);
        $this->assertSame('draft', $post->status);
        $this->assertNotNull($post->queued_at);
        $this->assertNotEmpty(IngestedItem::first()->evidence['text']);
        Http::assertNothingSent();
    }

    public function test_auto_publish_off_keeps_a_verified_article_as_draft(): void
    {
        $post = $this->candidate(false);
        Publisher::publish($post, app(ImageService::class), app(SeoBooster::class));
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_no_legacy_cap_score_or_gap_blocks_a_verified_article_and_delivery_is_idempotent(): void
    {
        Setting::put('daily_publish_cap', '1');
        Setting::put('min_publish_gap_minutes', '1440');
        Setting::put('publish_score_threshold', '100');
        Setting::put('truth_profiles', 'publisher,secondary');
        $post = $this->candidate();
        $post->update(['truth_profile' => 'publisher']);
        $this->assertSame([], PublishGate::failures($post));
        Publisher::publish($post, app(ImageService::class), app(SeoBooster::class));
        $post->refresh();
        $post->save();
        $this->assertSame('published', $post->status);
        $this->assertSame(1, NewsroomOutbox::count());
        $this->assertSame('publisher', NewsroomOutbox::first()->profile);
        $this->assertStringEndsWith('/post/alpha', NewsroomOutbox::first()->payload['article_url']);
    }

    public function test_critical_errors_cannot_be_overridden_by_a_ready_label(): void
    {
        $post = $this->candidate();
        $post->update(['fact_check' => ['verdict' => 'ready', 'critical' => 1]]);
        Publisher::publish($post, app(ImageService::class), app(SeoBooster::class));
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertArrayHasKey('fact-check', PublishGate::failures($post));
    }

    public function test_stale_revision_cannot_overwrite_a_newer_article(): void
    {
        $post = $this->candidate();
        $revision = PostRevision::create(['post_id' => $post->id, 'policy_version' => 'test', 'status' => 'pending_review', 'title' => $post->title,
            'body' => $post->body.'<p>New development.</p>', 'snapshot' => PostRevision::snapshotOf($post),
            'fact_check' => ['verdict' => 'ready', 'critical' => 0, 'unverified' => []]]);
        $post->update(['body' => $post->body.'<p>Editor update.</p>']);
        $this->expectException(\RuntimeException::class);
        $revision->apply();
    }

    public function test_outbox_retries_use_identical_payload_and_idempotency_key(): void
    {
        config(['services.truth_coordinator.url' => 'http://coordinator:8090', 'services.truth_coordinator.token' => str_repeat('y', 32)]);
        Setting::put('truth_profiles', 'publisher');
        $post = $this->candidate();
        $post->update(['truth_profile' => 'publisher', 'status' => 'published']);
        Http::fake(['coordinator:8090/*' => Http::sequence()->push([], 503)->push(['status' => 'pending_review'])->push(['status' => 'pending_review'])]);
        $this->artisan('newsroom:outbox')->assertSuccessful();
        $row = NewsroomOutbox::first();
        $key = $row->idempotency_key;
        $this->assertSame('delivery_failed', $row->status);
        $row->update(['next_attempt_at' => null]);
        $this->artisan('newsroom:outbox')->assertSuccessful();
        $this->assertSame($key, $row->fresh()->idempotency_key);
        $this->assertSame('pending_review', $row->fresh()->status);
    }

    public function test_local_research_writer_and_checker_prompts_fit_the_provisional_context(): void
    {
        $post = $this->candidate();
        $sources = [];
        for ($i = 1; $i <= 4; $i++) {
            $sources[] = ['name' => 'Source '.$i, 'url' => "https://source{$i}.test/report", 'published_at' => now()->toIso8601String(),
                'text' => str_repeat("District {$i} crews inspected the bridge and confirmed that repairs were complete. The county reopened access after inspectors approved the structure. ", 80)];
        }
        $packets = SourcePacket::prepare($sources, $post->title);
        $sizes = [];
        $ai = \Mockery::mock(AiClient::class)->makePartial();
        $ai->shouldReceive('json')->andReturnUsing(function ($stage, $system, $user, $properties) use (&$sizes) {
            $schema = ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
            $sizes[$stage] = strlen(json_encode([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], JSON_UNESCAPED_UNICODE)) + strlen(json_encode($schema));

            return null; // measure real prompts without running a model
        });
        app(ResearchAnalyst::class)->analyze($ai, $post, $packets);
        app(Rewriter::class)->writeArticle($ai, 'new', ['title' => $post->title, 'sources' => $packets, 'research' => $this->researchPackage(), 'min_words' => 500]);
        app(FactChecker::class)->check($ai, $post->title, str_repeat($post->body, 3), $packets, research: $this->researchPackage());
        $this->assertCount(3, $sizes);
        foreach ($sizes as $stage => $bytes) {
            $this->assertLessThanOrEqual((16384 - 4096 - 512) * 2, $bytes, "{$stage}: {$bytes} bytes exceeds the conservative gateway budget");
        }
    }
}
