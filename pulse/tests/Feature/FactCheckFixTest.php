<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\EditorialPipeline;
use App\Services\FactChecker;
use App\Services\Rewriter;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Source times stored in UTC; breaking stories get the breaking word floor;
 * a fact-check held ONLY by unverified claims gets a fix pass (remove or
 * attribute), while real evidence problems still hold the story.
 */
class FactCheckFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_source_publish_time_is_stored_in_utc(): void
    {
        $cat = Category::create(['name' => 'US News', 'slug' => 'us-news']);
        $post = Post::create(['title' => 'Guard member shot', 'slug' => 'guard-' . uniqid(), 'body' => '<p>Report.</p>', 'category_id' => $cat->id,
            'status' => 'draft', 'source_published_at' => Carbon::parse('2026-10-19T05:20:15-04:00')]);

        $this->assertSame('2026-10-19 09:20:15', $post->getRawOriginal('source_published_at'));
        $this->assertSame('2026-10-19T09:20:15+00:00', $post->fresh()->source_published_at->toIso8601String());
    }

    public function test_breaking_stories_get_the_breaking_word_floor_whatever_the_label(): void
    {
        Setting::put('min_publish_words', '500');
        $this->assertSame(350, PublishGate::minWords('crime', breaking: true));
        $this->assertSame(400, PublishGate::minWords('crime'));
        $this->assertSame(500, PublishGate::minWords('political'));
    }

    private function draft(): array
    {
        return ['headline' => 'Police say National Guard member was shot in Washington, DC', 'body' => '<p>Draft just blocks from the White House.</p>',
            'corrections' => [], 'change_summary' => '', 'story_type' => 'crime', 'excerpt' => 'E', 'meta_title' => 'M', 'meta_description' => 'D', 'takeaways' => []];
    }

    private function fc(string $verdict, array $unverified = [], array $issues = [], int $checked = 6): array
    {
        return ['verdict' => $verdict, 'claims_checked' => $checked, 'issues' => $issues, 'unverified' => $unverified, 'critical' => 0,
            'source_conflicts' => [], 'source_support' => [], 'sensitive' => false, 'summary' => ''];
    }

    private function runCheck(array $checks, ?callable $expectFix): array
    {
        $checker = \Mockery::mock(FactChecker::class);
        $checker->shouldReceive('check')->andReturnValues($checks);
        $this->app->instance(FactChecker::class, $checker);

        $writer = \Mockery::mock(Rewriter::class)->makePartial();
        if ($expectFix) {
            $writer->shouldReceive('writeArticle')->once()->withArgs(function ($ai, $mode, $brief) use ($expectFix) {
                $this->assertSame('fix', $mode);
                $expectFix($brief['feedback']);
                return true;
            })->andReturn(['body' => '<p>Draft near Farragut Square.</p>'] + $this->draft());
        } else {
            $writer->shouldNotReceive('writeArticle');
        }
        $this->app->instance(Rewriter::class, $writer);

        $method = new \ReflectionMethod(EditorialPipeline::class, 'checkAndFix');
        $method->setAccessible(true);

        return $method->invoke(app(EditorialPipeline::class), new AiClient, $this->draft(), [['name' => 'AP', 'url' => 'https://apnews.com/a', 'text' => 'x']], null);
    }

    public function test_a_hold_caused_only_by_unverified_claims_gets_a_fix_pass(): void
    {
        [$draft, $fc] = $this->runCheck(
            [$this->fc('needs_research', ['Whether "just blocks from the White House" is the best description']), $this->fc('ready')],
            function (string $feedback) {
                $this->assertStringContainsString('[UNVERIFIED — remove or attribute]', $feedback);
                $this->assertStringContainsString('just blocks from the White House', $feedback);
            },
        );
        $this->assertSame('ready', $fc['verdict']);
        $this->assertStringContainsString('Farragut Square', $draft['body']);
    }

    public function test_real_evidence_problems_still_hold_the_story(): void
    {
        // A critical error the checker can't say how to fix.
        [, $fc] = $this->runCheck([$this->fc('needs_research', ['A claim.'], [['claim' => 'Suspect named', 'problem' => 'No source names the suspect', 'severity' => 'critical', 'fix' => '']])], null);
        $this->assertSame('needs_research', $fc['verdict']);

        // Nothing could be checked at all.
        [, $fc] = $this->runCheck([$this->fc('needs_research', ['A claim.'], [], 0)], null);
        $this->assertSame('needs_research', $fc['verdict']);
    }
}
