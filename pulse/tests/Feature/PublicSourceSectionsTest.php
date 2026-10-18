<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\ArticleFetcher;
use App\Services\LinkEnricher;
use App\Services\Rewriter;
use App\Services\SeoOptimizer;
use App\Services\WebResearcher;
use App\Support\EditorialPolicy;
use App\Support\ExactQuoteVerifier;
use App\Support\NamedEntityVerifier;
use App\Support\PublicSourceSections;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicSourceSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Category::create(['name' => 'US News', 'slug' => 'us-news']);
        Category::create(['name' => 'Opinion', 'slug' => 'opinion']);
    }

    private function article(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'County bridge opens following inspection', 'slug' => 'bridge-' . uniqid(),
            'body' => '<p>According to <a href="https://county.gov/notice">the county statement</a>, the bridge reopened Friday.</p><p>Inspectors will return Monday.</p>',
            'status' => 'draft', 'category_id' => Category::where('slug', 'us-news')->value('id'),
            'sources' => [['name' => 'County', 'url' => 'https://county.gov/notice', 'supports' => ['bridge reopened']]],
            'fact_check' => ['verdict' => 'ready', 'claims_checked' => 2], 'review_status' => 'ready',
            'meta_title' => 'Bridge reopened', 'meta_description' => 'The bridge reopened after an inspection.',
        ]);
    }

    public function test_html_markdown_and_plain_source_sections_are_detected(): void
    {
        foreach ([
            '<h2>Sources</h2><ul><li><a href="https://apnews.com/a">AP</a></li></ul>',
            '<h3 class="foot"><em>References:</em></h3>', '<p><strong>Bibliography</strong></p>',
            '<div>Sources &amp; References</div>', '<p>Research sources:</p>',
            "## Sources\n- https://apnews.com/a", "**References:**\n- AP", "Sources\n======\nAP",
            "__Bibliography__\nAP", "<p>Sources:</p>\n<p>AP</p>",
        ] as $body) {
            $this->assertTrue(PublicSourceSections::hasSection($body), $body);
            $this->assertArrayHasKey('public-source-section', PublishGate::originality(new Post(['body' => $body])));
        }
    }

    public function test_legitimate_reporting_and_inline_attribution_are_not_sections(): void
    {
        foreach ([
            '<p>Reuters reported that officials will meet Monday.</p>',
            '<h2>Water sources remain contaminated</h2>', '<p>The bibliography lists 12 documents.</p>',
            '<p>According to <a href="https://county.gov/report">the report</a>, work starts Monday.</p>',
            '<p>The court references a previous ruling.</p>',
        ] as $body) {
            $this->assertFalse(PublicSourceSections::hasSection($body), $body);
        }
    }

    public function test_exact_quote_verifier_accepts_source_words_and_rejects_changed_quotes(): void
    {
        $sources = [['text' => 'The sheriff said, “We really want to let the law, the facts drive this.” ' . str_repeat('Officials confirmed the road reopened after the county inspection. ', 15)]];

        $this->assertSame([], ExactQuoteVerifier::findings('<p>He said, “the law, the facts drive this.”</p>', $sources));
        $findings = ExactQuoteVerifier::findings('<p>He said, “the law and facts will guide everything we do.”</p>', $sources);
        $this->assertCount(1, $findings);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertSame([], ExactQuoteVerifier::findings('<p>Officials called it “a key step.”</p>', $sources));
    }

    public function test_named_entity_verifier_blocks_full_names_not_present_in_research(): void
    {
        $sources = [['name' => 'New York Post', 'text' => 'Booker, a former state representative, is running against Rep. Andy Barr.']];

        $this->assertSame([], NamedEntityVerifier::findings('<p>The Democratic candidate Booker is running against Rep. Andy Barr.</p>', $sources));
        $findings = NamedEntityVerifier::findings('<p>The Democratic candidate Pamela Stevenson is running against Rep. Andy Barr.</p>', $sources);
        $this->assertCount(1, $findings);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertStringContainsString('Pamela', $findings[0]['problem']);
        $this->assertCount(1, NamedEntityVerifier::findings('<p>The Democrat, a former state representative Pamela Stevenson, is challenging Rep. Andy Barr.</p>', $sources));
    }

    public function test_fact_checker_deterministically_holds_a_changed_direct_quote(): void
    {
        Setting::put('openai_key', 'test');
        Http::fake(['api.openai.com/*' => function ($request) {
            $this->assertStringContainsString('do not invent a contradiction', $request['messages'][0]['content']);
            return Http::response(['choices' => [['message' => ['content' => json_encode([
            'claims_checked' => 5, 'issues' => [], 'unverified' => [], 'source_conflicts' => [],
            'source_support' => [], 'sensitive' => false, 'summary' => 'No issues found.',
            ])]]]]);
        }]);
        $source = ['name' => 'County', 'url' => 'https://county.gov/news', 'text' => 'The sheriff said, “We really want to let the law, the facts drive this.” ' . str_repeat('Officials confirmed the road reopened after the county inspection. ', 15)];
        $result = app(\App\Services\FactChecker::class)->check(new AiClient, 'County road reopens', '<p>He said, “The law and facts will guide everything we do.”</p>', [$source]);

        $this->assertSame('needs_rewrite', $result['verdict']);
        $this->assertSame(1, $result['critical']);
        $this->assertStringContainsString('contiguous passage', $result['issues'][0]['problem']);
    }

    public function test_manual_and_quiet_publication_cannot_bypass_the_rule(): void
    {
        foreach ([false, true] as $quiet) {
            $post = $this->article(['body' => '<p>Reporting.</p><h2>Sources</h2><p>AP</p>']);
            $post->status = 'published';
            try {
                $quiet ? $post->saveQuietly() : $post->save();
                $this->fail('Publication should have been blocked.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
                $this->assertSame('draft', $post->fresh()->status);
            }
        }
    }

    public function test_quiet_update_cannot_append_a_source_section_to_a_live_article(): void
    {
        $post = $this->article(['status' => 'published']);
        $original = $post->body;
        $post->body .= '<h2>References</h2><p>AP</p>';
        try {
            $post->saveQuietly();
            $this->fail('Update should have been blocked.');
        } catch (ValidationException) {
            $this->assertSame($original, $post->fresh()->body);
        }
    }

    public function test_article_page_keeps_reporting_metadata_and_internal_evidence(): void
    {
        $post = $this->article(['status' => 'published', 'published_at' => '2026-01-01 12:00:00']);
        $before = $post->only(['body', 'sources', 'fact_check', 'slug', 'published_at', 'meta_title', 'meta_description', 'author_id', 'featured_image']);
        $response = $this->get(route('post.show', $post));
        $response->assertOk()->assertDontSee('article-sources', false)->assertDontSee('>Sources</h', false)
            ->assertSee('https://county.gov/notice', false)->assertSee('Inspectors will return Monday.')
            ->assertSee('<link rel="canonical" href="' . route('post.show', $post), false)
            ->assertSee('Bridge reopened')->assertSee('2026-01-01T12:00:00', false);
        $this->assertEquals($before, $post->fresh()->only(array_keys($before)));
    }

    public function test_audit_is_repeatable_and_snapshots_original_content_without_mutation(): void
    {
        Storage::fake('local');
        $post = $this->article(['status' => 'published']);
        $before = $post->fresh()->getRawOriginal();
        $this->artisan('posts:audit-source-sections', ['--snapshot' => true])->assertSuccessful();
        $this->artisan('posts:audit-source-sections')->assertSuccessful();
        $file = Storage::disk('local')->files('editorial-audits')[0];
        $saved = json_decode(Storage::disk('local')->get($file), true);
        $this->assertSame($before, $saved['posts'][0]);
        $this->assertSame($before, $post->fresh()->getRawOriginal());
    }

    public function test_audit_flags_embedded_legacy_content_without_deleting_it(): void
    {
        $post = $this->article();
        $body = '<p>Keep this reporting.</p><h2>Sources</h2><p>AP</p><p>Keep this correction.</p>';
        // Simulates a legacy row created before the publication guard existed.
        \Illuminate\Support\Facades\DB::table('posts')->where('id', $post->id)->update([
            'body' => $body, 'status' => 'published', 'published_at' => '2026-01-01 00:00:00',
        ]);
        $this->artisan('posts:audit-source-sections')->expectsOutputToContain('1 embedded source-section candidates')->assertFailed();
        $this->assertSame($body, $post->fresh()->body);
    }

    public function test_link_enrichment_preserves_existing_publisher_attribution_links(): void
    {
        $html = '<p><a href="https://reuters.com/story" rel="noopener">Reuters</a> reported the vote.</p>';
        $this->assertSame($html, app(LinkEnricher::class)->sanitize($html, [], $html));
        Http::assertNothingSent();
    }

    public function test_enrichment_cannot_append_unverified_text_or_a_source_list(): void
    {
        Setting::put('openai_key', 'test');
        $post = $this->article();
        $before = $post->body;
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'body' => $before . '<h2>Sources</h2><p>New source</p>',
        ])]]]])]);
        $this->assertFalse(app(LinkEnricher::class)->enrichPost($post));
        $this->assertSame($before, $post->fresh()->body);
    }

    public function test_every_writer_mode_receives_the_permanent_rule(): void
    {
        $body = str_repeat('<p>County officials confirmed repairs were completed Friday and the bridge reopened after inspectors checked each support and the roadway surface.</p>', 12);
        foreach (['new', 'revision', 'fix'] as $mode) {
            $ai = \Mockery::mock(AiClient::class);
            $ai->shouldReceive('json')->once()->withArgs(function ($stage, $system) {
                $this->assertStringContainsString('Never append a standalone Sources, References, Bibliography', $system);
                $this->assertStringContainsString('Research with the available tools', $system);
                $this->assertStringContainsString('End when the reporting is complete', $system);
                $this->assertStringContainsString('Use only facts supported', $system);
                $this->assertStringContainsString('Never combine similar people', $system);
                $this->assertStringContainsString('Match a continuous passage', $system);
                $this->assertStringContainsString('stitch fragments around new paraphrase', $system);
                return true;
            })->andReturn([
                'body' => $body, 'headline' => 'Bridge open', 'excerpt' => 'Open Friday.',
                'story_type' => 'breaking', 'meta_title' => 'Bridge open', 'meta_description' => 'Open Friday.',
            ]);
            $draft = app(Rewriter::class)->writeArticle($ai, $mode, [
                'title' => 'Bridge open', 'sources' => [['name' => 'County', 'url' => 'https://county.gov/notice', 'text' => strip_tags($body)]],
            ]);
            $this->assertNotNull($draft);
            $this->assertFalse(PublicSourceSections::hasSection($draft['body']));
        }
    }

    public function test_preview_call_and_output_limits_are_enforced_even_after_failure(): void
    {
        Setting::put('openai_key', 'test');
        Http::fake(['api.openai.com/*' => Http::response([], 503)]);
        $ai = new AiClient(maxCalls: 1, maxCompletionTokens: 3000, maxInputBytes: 100000);
        $this->assertNull($ai->json('preview', 'Rules', 'Draft', ['body' => ['type' => 'string']]));
        $this->assertNull($ai->json('preview', 'Rules', 'Draft', ['body' => ['type' => 'string']]));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['max_completion_tokens'] === 3000);
    }

    public function test_preview_rejects_oversized_input_without_an_api_call(): void
    {
        Setting::put('openai_key', 'test');
        $ai = new AiClient(maxCalls: 1, maxCompletionTokens: 3000, maxInputBytes: 100);
        $this->assertNull($ai->json('preview', str_repeat('x', 101), 'Draft', ['body' => ['type' => 'string']]));
        Http::assertNothingSent();
    }

    public function test_unverified_claims_never_receive_ready_even_without_critical_findings(): void
    {
        Setting::put('openai_key', 'test');
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'claims_checked' => 12, 'issues' => [], 'unverified' => ['The bridge was inspected twice.'],
            'source_conflicts' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'Review needed.',
        ])]]]])]);
        $fc = app(\App\Services\FactChecker::class)->check(new AiClient, 'Bridge reopened', '<p>The bridge was inspected twice.</p>', [
            ['name' => 'County', 'url' => 'https://county.gov/notice', 'text' => str_repeat('The bridge opened Friday after inspection. ', 20)],
        ]);
        $this->assertSame('needs_research', $fc['verdict']);
        $this->assertSame(['The bridge was inspected twice.'], $fc['unverified']);
        $post = $this->article(['review_status' => 'ready', 'fact_check' => $fc]);
        $this->assertArrayHasKey('unverified-claims', PublishGate::failures($post));
    }

    public function test_private_preview_never_changes_or_publishes_the_article(): void
    {
        Storage::fake('local');
        Setting::put('openai_key', 'test');
        $post = $this->article(['status' => 'published', 'published_at' => '2026-01-01 12:00:00']);
        $before = $post->fresh()->getRawOriginal();
        $body = str_repeat('<p>County officials inspected the bridge on Friday and reopened the road after repair crews finished their work on the damaged supports.</p>', 12);
        $pipeline = \Mockery::mock(\App\Services\EditorialPipeline::class);
        $pipeline->shouldReceive('recordedEvidence')->once()->andReturn([
            ['name' => 'County', 'url' => 'https://county.gov/notice', 'text' => strip_tags($body)],
            ['name' => 'Local News', 'url' => 'https://localnews.test/bridge', 'text' => strip_tags($body)],
        ]);
        $this->app->instance(\App\Services\EditorialPipeline::class, $pipeline);
        Http::fake(['api.openai.com/*' => function ($r) use ($body) {
            $check = $r['response_format']['json_schema']['name'] === 'fact_check';
            if ($check) {
                $this->assertStringContainsString('reread the exact sentence', $r['messages'][0]['content']);
                $this->assertStringContainsString('originally published at', $r['messages'][0]['content']);
            }
            $data = $check
                ? ['claims_checked' => 2, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'Checked.']
                : ['headline' => 'Bridge reopened', 'body' => $body, 'excerpt' => 'Open Friday.', 'story_type' => 'breaking', 'meta_title' => 'Bridge', 'meta_description' => 'Open.'];
            return Http::response(['choices' => [['message' => ['content' => json_encode($data)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]]);
        }]);
        $this->artisan('posts:preview-writing', ['post' => $post->id, '--check' => true])->assertSuccessful();
        $this->assertSame($before, $post->fresh()->getRawOriginal());
        $this->assertSame(1, Post::count());
        Http::assertSentCount(2);
        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('editorial-previews')[0]), true);
        $this->assertTrue($report['preview_only']);
        $this->assertSame(2, $report['usage']['calls']);
        $this->assertSame('ready', $report['fact_check']['verdict']);
        $this->assertArrayHasKey('single-source', $report['publish_gate_failures']);
    }

    public function test_scheduled_synthesis_runs_research_writer_and_fact_checker_and_keeps_records(): void
    {
        Setting::put('min_sources_to_publish', '2');
        Setting::put('openai_key', 'test');
        $post = $this->article(['source_url' => 'https://county.gov/notice', 'sources' => [], 'queued_at' => now(), 'review_status' => null]);
        $text = str_repeat('County inspectors checked the bridge supports Friday before reopening the road. ', 30);
        $fetcher = \Mockery::mock(ArticleFetcher::class);
        $fetcher->shouldReceive('extract')->with('https://county.gov/notice')->andReturn(['text' => $text, 'title' => 'Bridge inspection complete']);
        $this->app->instance(ArticleFetcher::class, $fetcher);
        $research = \Mockery::mock(WebResearcher::class)->makePartial();
        $research->shouldReceive('enabled')->andReturn(true);
        $research->shouldReceive('research')->once()->andReturn([
            ['name' => 'Local News', 'url' => 'https://localnews.test/bridge', 'text' => str_repeat('Traffic resumed on the county crossing after repair crews finished work and engineers approved the structure. ', 30), 'title' => 'Drivers return to repaired crossing', 'accessed_at' => now()->toIso8601String()],
        ]);
        $this->app->instance(WebResearcher::class, $research);
        $seo = \Mockery::mock(SeoOptimizer::class);
        $seo->shouldReceive('optimizePost')->once()->andReturn([]);
        $this->app->instance(SeoOptimizer::class, $seo);
        $calls = [];
        Http::fake(['api.openai.com/*' => function ($request) use (&$calls) {
            $stage = $request['response_format']['json_schema']['name'];
            $calls[] = $stage;
            if ($stage === 'fact_check') {
                $data = ['claims_checked' => 4, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [['source' => 1, 'claims' => ['bridge reopened']]], 'sensitive' => false, 'summary' => 'Verified.'];
            } else {
                $this->assertStringContainsString('Never append a standalone Sources', $request['messages'][0]['content']);
                $body = '';
                for ($i = 1; $i <= 12; $i++) {
                    $body .= "<p>Inspectors reviewed bridge support number {$i} on Friday and confirmed it met the county standards for traffic before the road reopened.</p>";
                }
                $data = ['headline' => 'County crossing back in service', 'body' => $body, 'excerpt' => 'The county reopened the road.', 'story_type' => 'breaking', 'meta_title' => 'Crossing reopened', 'meta_description' => 'The county reopened the road.', 'takeaways' => [], 'corrections' => [], 'update_note' => '', 'change_summary' => ''];
            }
            return Http::response(['choices' => [['message' => ['content' => json_encode($data)]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100]]);
        }]);
        $this->artisan('posts:synthesize', ['--limit' => 1])->assertSuccessful();
        $post->refresh();
        $this->assertSame('ready', $post->review_status);
        $this->assertSame('draft', $post->status);
        $this->assertCount(2, $post->sources);
        $this->assertSame(['bridge reopened'], $post->sources[0]['supports']);
        $this->assertGreaterThan(0, $post->fact_check['claims_checked']);
        $this->assertContains('fact_check', $calls);
        $this->assertFalse(PublicSourceSections::hasSection($post->body));
        $this->assertSame(EditorialPolicy::version(), $post->policy_version);
    }
}
