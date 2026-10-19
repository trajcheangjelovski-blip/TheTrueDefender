<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Services\ArticleFetcher;
use App\Services\EditorialPipeline;
use App\Services\ResearchAnalyst;
use App\Services\Rewriter;
use App\Services\WebResearcher;
use App\Support\AiModels;
use App\Support\ArticleSections;
use App\Support\FinalCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * BRAVE → GPT-5.4 RESEARCH → GPT-5.4-mini WRITING → GPT-5.4 FACT-CHECK → FINAL CHECK.
 * The eight regression scenarios from the brief, run through the real pipeline
 * with the AI and page fetching faked (no network).
 */
class ResearchPipelineTest extends TestCase
{
    use RefreshDatabase, Concerns\ResearchFixtures;

    private Category $news;

    /** @var array<string,array> stage => request data */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->news = Category::create(['name' => 'US News', 'slug' => 'us-news']);
        Category::create(['name' => 'Opinion', 'slug' => 'opinion']);
        Setting::put('openai_key', 'test');
        Setting::put('min_publish_words', '150');
    }

    /** Pages by URL: [url => text]. */
    private function pages(array $pages): void
    {
        $fetcher = \Mockery::mock(ArticleFetcher::class);
        // Each outlet has its own headline and opening (identical ones are treated as republished copies).
        $fetcher->shouldReceive('extract')->andReturnUsing(fn ($url) => [
            'text' => isset($pages[$url]) ? 'Reporting by ' . parse_url($url, PHP_URL_HOST) . '. ' . $pages[$url] : null,
            'title' => 'Report from ' . parse_url($url, PHP_URL_HOST), 'image' => null,
            'published_at' => now()->toIso8601String(), 'modified_at' => null,
        ]);
        $this->app->instance(ArticleFetcher::class, $fetcher);
    }

    private function text(string $topic, int $n = 30): string
    {
        $out = '';
        for ($i = 1; $i <= $n; $i++) {
            $out .= "Officials said detail {$i} of the {$topic} was confirmed on Monday by investigators at the scene. ";
        }

        return $out;
    }

    private function article(string $extra = ''): string
    {
        $b = '';
        for ($i = 1; $i <= 12; $i++) {
            $b .= "<p>Police confirmed point {$i} about the shooting near Farragut Square on Monday, according to <a href=\"https://mpdc.dc.gov/x\">the Metropolitan Police Department</a>.</p>";
        }

        return $b . $extra;
    }

    private array $research = [];

    private array $responses = [];

    private bool $faked = false;

    /** Fake every AI stage; $research is the package, $responses per stage. Re-callable within a test. */
    private function ai(array $research, array $responses = []): void
    {
        $this->research = $research;
        $this->responses = $responses;
        if ($this->faked) {
            return;   // Http fakes stack; the one closure reads the current responses
        }
        $this->faked = true;
        Http::fake(['api.openai.com/*' => function ($request) {
            $research = $this->research;
            $responses = $this->responses;
            $stage = $request['response_format']['json_schema']['name'] ?? 'other';
            $this->sent[$stage] = $request->data();
            if (array_key_exists($stage, $responses) && $responses[$stage] === 500) {
                return Http::response(['error' => ['message' => 'server error']], 500);
            }
            // '_other_incident' => URL: mark whichever packet carries that URL as a different
            // incident, by the numbering the research model actually sees (sources are tier-sorted).
            if ($stage === 'research' && isset($research['_other_incident'])) {
                preg_match_all('/--- PACKET (\d+) .*?\nURL: (\S+)/s', (string) $request['messages'][1]['content'], $m, PREG_SET_ORDER);
                $research['packets'] = array_map(fn ($p) => ['packet' => (int) $p[1], 'same_event' => $p[2] !== $research['_other_incident'],
                    'published' => '', 'updated' => '', 'note' => $p[2] === $research['_other_incident'] ? 'Different incident (Nov 2025).' : ''], $m);
                unset($research['_other_incident']);
            }
            $data = match ($stage) {
                'research' => $research,
                'article_write', 'article_fix' => $responses[$stage] ?? ['headline' => 'Police say National Guard member was shot in Washington, DC',
                    'body' => $this->article(), 'excerpt' => 'Police confirmed the shooting.', 'story_type' => 'crime', 'meta_title' => 'Guard member shot',
                    'meta_description' => 'Police confirmed a National Guard member was shot in Washington, D.C.', 'corrections' => [], 'update_note' => '', 'change_summary' => '',
                    'status' => 'ready', 'reporting_gaps' => []],
                'fact_check' => $responses['fact_check'] ?? ['claims_checked' => 8, 'issues' => [], 'source_conflicts' => [], 'unverified' => [],
                    'source_support' => [['source' => 1, 'claims' => ['shooting confirmed']]], 'sensitive' => true, 'summary' => 'Verified.'],
                default => [],
            };

            return Http::response(['choices' => [['message' => ['content' => json_encode($data)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200]]);
        }]);
    }

    private function story(array $sourceUrls, array $attrs = []): Post
    {
        return Post::create($attrs + [
            'title' => 'Police say National Guard member was shot in Washington, DC', 'slug' => 'guard-' . uniqid(),
            'body' => $this->article(), 'category_id' => $this->news->id, 'status' => 'draft', 'queued_at' => now(),
            'source_url' => $sourceUrls[0], 'is_breaking' => true,
            'sources' => array_map(fn ($u) => ['name' => parse_url($u, PHP_URL_HOST), 'url' => $u], $sourceUrls),
        ]);
    }

    // ── TEST 1 — two different National Guard shootings ────────────────────

    public function test_1_a_different_incident_is_excluded_before_writing(): void
    {
        $oct = 'https://www.foxnews.com/us/guard-shot-oct-2026';
        $nov = 'https://www.oldnews.com/guard-shot-white-house-nov-2025';
        $wapo = 'https://www.washingtonpost.com/dc/guard-shot-farragut';
        $this->pages([$oct => $this->text('Farragut Square shooting'), $nov => $this->text('November 2025 White House shooting'), $wapo => $this->text('Farragut shooting')]);
        $this->ai($this->researchPackage() + ['_other_incident' => $nov]);
        $post = $this->story([$oct, $nov, $wapo]);

        $this->assertNull(app(EditorialPipeline::class)->processDraft($post, 2));
        $post->refresh();

        $writerPrompt = $this->sent['article_write']['messages'][1]['content'];
        $this->assertStringNotContainsString($nov, $writerPrompt);           // never reaches the writer
        $this->assertStringContainsString($oct, $writerPrompt);
        $this->assertStringContainsString('VERIFIED RESEARCH PACKAGE', $writerPrompt);
        $this->assertNotContains($nov, array_column($post->sources, 'url'));
        $this->assertSame('ready', $post->review_status);
        $this->assertSame(now()->toDateString() . '|washington-dc|shooting', $post->event_key);
        $this->assertStringContainsString('DIFFERENT incident', $this->sent['fact_check']['messages'][0]['content'] ?? '');
        $this->assertNotSame(
            ResearchAnalyst::eventKey(['event_date' => '2025-11-26', 'where' => 'Washington, D.C.', 'event_type' => 'shooting']),
            $post->event_key,
        );
    }

    // ── TEST 2 — El Niño: the newer development leads, the September item is dated background ──

    public function test_2_newer_development_leads_and_older_announcement_is_dated_background(): void
    {
        $package = $this->researchPackage([
            'event' => ['title' => 'California El Niño outlook', 'where' => 'California', 'event_type' => 'weather-outlook', 'event_date' => now()->subDay()->toDateString(), 'ongoing' => true],
            'newsworthiness' => 'new_development', 'breaking_eligible' => false,
            'latest_development' => ['date' => now()->toDateString(), 'summary' => 'NOAA raised the odds of a strong El Niño.'],
            'background' => [['fact' => 'On Sept. 12 the governor declared a preparedness emergency.', 'packets' => [2]]],
        ]);
        $block = ResearchAnalyst::writerBlock($package);
        $this->assertStringContainsString('NOAA raised the odds', $block);
        $this->assertStringContainsString('BACKGROUND (older — always give its date, never present as new)', $block);
        $this->assertStringContainsString('Sept. 12', $block);
        $this->assertNull(ResearchAnalyst::assess($package)['hold']);

        // Its newest development being 3 weeks old makes it NOT current news.
        $stale = array_replace_recursive($package, ['latest_development' => ['date' => now()->subWeeks(3)->toDateString()]]);
        $this->assertStringContainsString('not current news', (string) ResearchAnalyst::assess($stale)['hold']);
    }

    // ── TEST 3 — Norte Fire: superseded evacuation info is caught before publishing ──

    public function test_3_developing_story_with_newer_coverage_is_sent_back_before_publication(): void
    {
        $post = $this->story(['https://www.ktla.com/norte-fire'], [
            'title' => 'Norte Fire evacuation orders issued', 'review_status' => 'ready', 'web_researched_at' => now()->subHour(),
            'research_package' => $this->researchPackage([
                'event' => ['title' => 'Norte Fire', 'where' => 'California', 'event_type' => 'wildfire', 'ongoing' => true],
                'researched_at' => now()->subHour()->toIso8601String(),
            ]),
        ]);
        $research = \Mockery::mock(WebResearcher::class)->makePartial();
        $research->shouldReceive('newerCoverage')->once()->andReturn([['name' => 'CAL FIRE', 'url' => 'https://www.fire.ca.gov/norte']]);
        $this->app->instance(WebResearcher::class, $research);

        $fails = FinalCheck::failures($post);
        $this->assertArrayHasKey('superseded', $fails);
        $this->assertStringContainsString('CAL FIRE', $fails['superseded']);
        $this->assertNull($post->fresh()->web_researched_at);                  // back through research
    }

    // ── TEST 4 — similar headlines, different events / same event again ────

    public function test_4_event_identity_not_headline_decides_duplicates(): void
    {
        $url = 'https://www.foxnews.com/us/guard-a';
        $other = 'https://www.washingtonpost.com/dc/guard-b';
        $this->pages([$url => $this->text('Farragut shooting'), $other => $this->text('Farragut shooting')]);

        // Already published: the same event (same date, place, type), same latest development.
        Post::create(['title' => 'Guard member shot in DC', 'slug' => 'old-' . uniqid(), 'body' => '<p>x</p>', 'category_id' => $this->news->id,
            'status' => 'published', 'published_at' => now()->subHour(), 'event_key' => now()->toDateString() . '|washington-dc|shooting',
            'research_package' => $this->researchPackage(), 'push_notified_at' => now(), 'social_posted_at' => now()]);

        $this->ai($this->researchPackage());
        $dup = $this->story([$url, $other]);
        $this->assertStringContainsString('Same event already covered', (string) app(EditorialPipeline::class)->processDraft($dup, 2));
        $this->assertSame('rejected', $dup->fresh()->review_status);

        // Similar headline, but a different incident (other date) → its own story.
        $this->ai($this->researchPackage(['event' => ['event_date' => now()->subYear()->toDateString()], 'newsworthiness' => 'new_development',
            'latest_development' => ['date' => now()->toDateString()]]));
        $different = $this->story([$url, $other], ['slug' => 'diff-' . uniqid()]);
        $this->assertNull(app(EditorialPipeline::class)->processDraft($different, 2));
        $this->assertSame('ready', $different->fresh()->review_status);
    }

    // ── TEST 5 — an old incident republished with today's date ─────────────

    public function test_5_republished_old_event_is_rejected_not_published_as_new(): void
    {
        $url = 'https://www.recycled.com/guard-shooting';
        $other = 'https://www.nbc.com/guard-2025';
        $this->pages([$url => $this->text('November 2025 shooting'), $other => $this->text('November 2025 shooting')]);
        $this->ai($this->researchPackage([
            'event' => ['event_date' => '2025-11-26'], 'newsworthiness' => 'republished_old_event', 'breaking_eligible' => false,
            'latest_development' => ['date' => '2025-11-27'],
        ]));
        $post = $this->story([$url, $other]);

        $why = (string) app(EditorialPipeline::class)->processDraft($post, 2);
        $this->assertStringContainsString('Republished old event', $why);
        $post->refresh();
        $this->assertSame('rejected', $post->review_status);
        $this->assertNull($post->queued_at);
        $this->assertFalse($post->is_breaking);                               // misleading label removed
        $this->assertArrayNotHasKey('article_write', $this->sent);            // nothing was written
        $this->assertArrayHasKey('research', FinalCheck::failures($post));
    }

    // ── TEST 6 — research / fact-check / model failures hold the article ────

    public function test_6_research_failure_holds_the_article_and_records_why(): void
    {
        $url = 'https://www.foxnews.com/us/a';
        $other = 'https://www.apnews.com/a';
        $this->pages([$url => $this->text('shooting'), $other => $this->text('shooting')]);
        $this->ai([], ['research' => 500]);
        $post = $this->story([$url, $other]);

        $this->assertStringContainsString('research stage failed', (string) app(EditorialPipeline::class)->processDraft($post, 2));
        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('needs_research', $post->review_status);
        $this->assertStringContainsString('gpt-5.4', $post->research_package['error']);
        $this->assertArrayNotHasKey('article_write', $this->sent);
    }

    public function test_6_fact_check_failure_is_never_a_pass(): void
    {
        $url = 'https://www.foxnews.com/us/a';
        $other = 'https://www.apnews.com/a';
        $this->pages([$url => $this->text('shooting'), $other => $this->text('shooting')]);
        $this->ai($this->researchPackage(), ['fact_check' => 500]);
        $post = $this->story([$url, $other]);

        app(EditorialPipeline::class)->processDraft($post, 2);
        $post->refresh();
        $this->assertSame('needs_research', $post->review_status);
        $this->assertNotEmpty($post->fact_check['error']);
        $this->assertSame('draft', $post->status);
    }

    public function test_6_unavailable_model_holds_instead_of_falling_back(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-5.4-mini']]])]);
        $error = null;
        $package = app(ResearchAnalyst::class)->analyze(new \App\Services\AiClient, $this->story(['https://a.test/x']), [], $error);
        $this->assertNull($package);
        $this->assertStringContainsString('gpt-5.4 is not available', $error);
    }

    // ── TEST 7 — the writer stays gpt-5.4-mini ──────────────────────────────

    public function test_7_models_are_separate_and_the_writer_stays_mini(): void
    {
        $this->assertSame('gpt-5.4', AiModels::research());
        $this->assertSame('gpt-5.4-mini', AiModels::writer());
        $this->assertSame('gpt-5.4', AiModels::factCheck());
        $this->assertSame('medium', AiModels::researchEffort());
        $this->assertSame([2.50, 0.25, 15.00], AiModels::price('gpt-5.4-2026-03-05'));
        $this->assertSame([0.75, 0.075, 4.50], AiModels::price('gpt-5.4-mini'));

        // Utility calls (e.g. the editorial score) use the writing model, not gpt-5.4.
        $model = null;
        Http::fake(['api.openai.com/*' => function ($r) use (&$model) {
            $model = $r['model'];
            return Http::response(['choices' => [['message' => ['content' => json_encode(['score' => 70, 'urgent' => false, 'reason' => 'ok'])]]]]);
        }]);
        app(Rewriter::class)->score('Headline', 'Summary', 'politics');
        $this->assertSame('gpt-5.4-mini', $model);

        // Saved settings win over the defaults.
        Setting::put('research_model', 'gpt-5.5');
        $this->assertSame('gpt-5.5', AiModels::research());
    }

    // ── TEST 8 — formatting rules hold through the pipeline ─────────────────

    public function test_8_pipeline_output_has_no_disabled_sections_and_keeps_inline_attribution(): void
    {
        $url = 'https://www.foxnews.com/us/a';
        $other = 'https://www.apnews.com/a';
        $this->pages([$url => $this->text('shooting'), $other => $this->text('shooting')]);
        $this->ai($this->researchPackage(), ['article_write' => ['headline' => 'Police say National Guard member was shot in Washington, DC',
            'body' => $this->article('<h2>Key Takeaways</h2><ul><li>Shot.</li></ul><h2>Our Take</h2><p>We think so.</p><h2>Sources</h2><ul><li>AP</li></ul>'),
            'excerpt' => 'E', 'story_type' => 'crime', 'meta_title' => 'M', 'meta_description' => 'D', 'takeaways' => ['x'], 'corrections' => [],
            'update_note' => '', 'change_summary' => '', 'status' => 'ready', 'reporting_gaps' => []]]);
        $post = $this->story([$url, $other]);

        app(EditorialPipeline::class)->processDraft($post, 2);
        $post->refresh();
        $this->assertSame([], ArticleSections::found($post->body));
        $this->assertEmpty($post->takeaways);
        $this->assertStringContainsString('href="https://mpdc.dc.gov/x"', $post->body);   // inline attribution kept
    }

    // ── Cost tracking per model ─────────────────────────────────────────────

    public function test_costs_are_recorded_per_model_at_each_models_price(): void
    {
        $url = 'https://www.foxnews.com/us/a';
        $other = 'https://www.apnews.com/a';
        $this->pages([$url => $this->text('shooting'), $other => $this->text('shooting')]);
        $this->ai($this->researchPackage());
        $post = $this->story([$url, $other]);

        app(EditorialPipeline::class)->processDraft($post, 2);
        $u = $post->fresh()->ai_usage;
        // research + fact-check on gpt-5.4: 1000 in × $2.50 + 200 out × $15 per 1M = $0.0055 each.
        $this->assertEqualsWithDelta(0.0110, $u['by_model']['gpt-5.4']['usd'], 0.00001);
        // writing on gpt-5.4-mini: 1000 × $0.75 + 200 × $4.50 per 1M = $0.00165.
        $this->assertEqualsWithDelta(0.00165, $u['by_model']['gpt-5.4-mini']['usd'], 0.00001);
        $this->assertEqualsWithDelta(0.01265, $u['estimated_usd'], 0.0001);
        $this->assertArrayHasKey('brave_requests', $u);
    }
}
