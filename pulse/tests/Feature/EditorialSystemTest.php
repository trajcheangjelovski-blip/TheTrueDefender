<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\FactChecker;
use App\Support\EditorialPolicy;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The editorial system's safeguards: policy loading/versioning, the publish
 * gate (fact-check requirement, originality and opinion-in-news rules), the
 * independent fact-check verdict logic, and revision apply/rollback.
 * All AI/HTTP traffic is faked — these tests never call a real service.
 */
class EditorialSystemTest extends TestCase
{
    use RefreshDatabase;

    private Category $news;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();                 // no social-posting / push jobs
        Http::preventStrayRequests();  // any real network call fails the test
        $this->news = Category::create(['name' => 'US News', 'slug' => 'us-news']);
        Category::create(['name' => 'Opinion', 'slug' => 'opinion']);
        Setting::put('min_publish_words', '120');
        Setting::put('min_sources_to_publish', '2');
    }

    private function paragraphs(int $n, string $seed = 'alpha'): string
    {
        $out = '';
        for ($i = 1; $i <= $n; $i++) {
            $out .= "<p>Officials in county {$seed}{$i} confirmed on Friday that crews had cleared road number {$i} and reopened it to traffic by noon.</p>";
        }

        return $out;
    }

    private function newsAttrs(array $attrs = []): array
    {
        return $attrs + [
            'title' => 'County reopens flooded roads after storm',
            'slug' => 'county-reopens-' . uniqid(),
            'body' => $this->paragraphs(8),
            'category_id' => $this->news->id,
            'review_status' => 'ready',
            'sources' => [['name' => 'Associated Press', 'url' => 'https://apnews.com/a'], ['name' => 'Reuters', 'url' => 'https://reuters.com/b']],
            'push_notified_at' => now(),
            'social_posted_at' => now(),
        ];
    }

    private function newsPost(array $attrs = []): Post
    {
        return new Post($this->newsAttrs($attrs));
    }

    // ── Policy ──────────────────────────────────────────────────────────────

    public function test_policy_is_versioned_and_loaded_into_prompts(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}\.\d{2}-\d+$/', EditorialPolicy::version());
        $this->assertStringContainsString('Accuracy (non-negotiable)', EditorialPolicy::text());
        $this->assertStringContainsString('EDITORIAL POLICY v' . EditorialPolicy::version(), EditorialPolicy::forPrompt());
    }

    public function test_house_voice_is_subordinate_to_the_policy(): void
    {
        Setting::put('ai_instructions', 'Be punchy.');
        $prompt = EditorialPolicy::forPrompt();
        $this->assertLessThan(strpos($prompt, 'Be punchy.'), strpos($prompt, 'Accuracy (non-negotiable)'));
        $this->assertStringContainsString('only where they do not conflict with the policy', $prompt);
    }

    // ── Publish gate ────────────────────────────────────────────────────────

    public function test_a_verified_original_article_passes(): void
    {
        $this->assertSame([], PublishGate::failures($this->newsPost()));
    }

    public function test_unverified_news_cannot_publish(): void
    {
        foreach ([null, 'needs_rewrite', 'needs_research', 'rejected'] as $status) {
            $this->assertArrayHasKey('not-verified', PublishGate::failures($this->newsPost(['review_status' => $status])), (string) $status);
        }
    }

    public function test_headline_echo_is_blocked(): void
    {
        $post = $this->newsPost(['body' => '<p>County reopens flooded roads after storm damage closed them for days across the region.</p>' . $this->paragraphs(8)]);
        $this->assertArrayHasKey('headline-echo', PublishGate::failures($post));
    }

    public function test_repeated_sentence_is_blocked(): void
    {
        $p = '<p>Officials confirmed on Friday that the main bridge into town will stay closed through the weekend.</p>';
        $this->assertArrayHasKey('repeated-sentence', PublishGate::failures($this->newsPost(['body' => $p . $this->paragraphs(8) . $p])));
    }

    public function test_stock_ai_phrases_from_the_policy_are_blocked(): void
    {
        foreach (['This serves as a stark reminder of the risks.', 'In a shocking turn of events, the council voted.', 'The incident raises serious questions about planning.'] as $s) {
            $post = $this->newsPost(['body' => $this->paragraphs(8) . "<p>{$s}</p>"]);
            $this->assertArrayHasKey('stock-phrase', PublishGate::failures($post), $s);
        }
    }

    public function test_generic_template_headings_are_blocked(): void
    {
        $post = $this->newsPost(['body' => $this->paragraphs(4) . '<h2>Background &amp; Context</h2>' . $this->paragraphs(4, 'beta')]);
        $this->assertArrayHasKey('generic-heading', PublishGate::failures($post));
    }

    public function test_opinion_section_inside_news_is_blocked(): void
    {
        $post = $this->newsPost(['body' => $this->paragraphs(8) . '<h2>Our Take</h2><p>We think the county acted well and deserves credit for it.</p>']);
        $this->assertArrayHasKey('opinion-in-news', PublishGate::failures($post));
    }

    public function test_rhetorical_questions_are_blocked_but_quoted_questions_are_fine(): void
    {
        $rhetorical = $this->newsPost(['body' => $this->paragraphs(8) . '<p>Who pays for this? And who answers for it?</p>']);
        $this->assertArrayHasKey('rhetorical-question', PublishGate::failures($rhetorical));

        $quoted = $this->newsPost(['body' => $this->paragraphs(8) . '<p>The mayor asked, "Who pays for this?" and "When will it end?" at the meeting.</p>']);
        $this->assertArrayNotHasKey('rhetorical-question', PublishGate::failures($quoted));
    }

    public function test_short_breaking_report_is_not_rejected_as_thin(): void
    {
        Setting::put('min_publish_words', '600');
        $body = $this->paragraphs(18);   // ~380 words
        $this->assertArrayHasKey('thin', PublishGate::failures($this->newsPost(['body' => $body])));
        $this->assertArrayNotHasKey('thin', PublishGate::failures($this->newsPost(['body' => $body, 'story_type' => 'breaking'])));
    }

    public function test_related_link_line_is_not_a_reused_sentence(): void
    {
        $related = '<p class="related-inline">Related: <a href="/post/x">Storm closes every bridge across the northern county for days</a></p>';
        Post::create($this->newsAttrs(['status' => 'published', 'published_at' => now(), 'body' => $this->paragraphs(8, 'gamma') . $related]));
        $this->assertArrayNotHasKey('reused-sentence', PublishGate::failures($this->newsPost(['body' => $this->paragraphs(8) . $related])));
    }

    // ── Independent fact-check ──────────────────────────────────────────────

    private function fakeFactCheck(array $issues, int $checked = 12): void
    {
        Setting::put('openai_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'claims_checked' => $checked, 'issues' => $issues, 'source_conflicts' => [], 'unverified' => [],
                'source_support' => [['source' => 1, 'claims' => ['roads reopened']]], 'sensitive' => false, 'summary' => 'ok',
            ])]]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200],
        ])]);
    }

    private function sources(): array
    {
        return [['name' => 'Associated Press', 'url' => 'https://apnews.com/a', 'text' => str_repeat('Crews reopened the roads on Friday after the storm. ', 20)]];
    }

    public function test_fact_check_with_no_evidence_never_passes_and_makes_no_call(): void
    {
        Setting::put('openai_key', 'test-key');
        $r = app(FactChecker::class)->check(new AiClient, 'T', '<p>x</p>', []);
        $this->assertSame('needs_research', $r['verdict']);
        Http::assertNothingSent();
    }

    /** Each case runs in a fresh app, so one fake AI response per case. */
    public static function verdictCases(): array
    {
        return [
            'no issues → ready' => [[], 12, 'ready'],
            'fixable critical error → needs_rewrite' => [[['claim' => 'age 34', 'problem' => 'source says 43', 'severity' => 'critical', 'fix' => 'age 43']], 12, 'needs_rewrite'],
            'unfixable critical error → needs_research' => [[['claim' => 'rifle', 'problem' => 'no source names the weapon', 'severity' => 'critical', 'fix' => '']], 12, 'needs_research'],
            'only minor issues → ready' => [[['claim' => 'tone', 'problem' => 'slightly strong', 'severity' => 'minor', 'fix' => 'soften']], 12, 'ready'],
            'nothing actually checked → needs_research' => [[], 0, 'needs_research'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verdictCases')]
    public function test_fact_check_verdicts_are_decided_in_code(array $issues, int $checked, string $expected): void
    {
        $this->fakeFactCheck($issues, $checked);
        $this->assertSame($expected, app(FactChecker::class)->check(new AiClient, 'T', $this->paragraphs(3), $this->sources())['verdict']);
    }

    public function test_ai_usage_is_recorded_per_call(): void
    {
        $this->fakeFactCheck([]);
        $ai = new AiClient;
        app(FactChecker::class)->check($ai, 'T', $this->paragraphs(3), $this->sources());
        $u = $ai->usage();
        $this->assertSame(1, $u['calls']);
        $this->assertSame(1200, $u['prompt_tokens'] + $u['completion_tokens']);
    }

    // ── Revisions: approval, history preservation, rollback ─────────────────

    private function publishedPostWithRevision(string $status = 'pending_review', array $corrections = []): array
    {
        $post = Post::create($this->newsAttrs([
            'status' => 'published', 'published_at' => now()->subDays(3), 'featured_image' => 'img/a.jpg', 'slug' => 'original-slug-' . uniqid(),
        ]));
        $rev = PostRevision::create([
            'post_id' => $post->id, 'status' => $status, 'policy_version' => EditorialPolicy::version(),
            'title' => 'County reopens 12 roads after weekend storm', 'body' => '<p>Revised body text.</p>',
            'snapshot' => PostRevision::snapshotOf($post), 'change_log' => ['summary' => 's', 'corrections' => $corrections],
        ]);

        return [$post, $rev];
    }

    public function test_applying_a_revision_keeps_url_date_and_image_and_discloses_corrections(): void
    {
        [$post, $rev] = $this->publishedPostWithRevision('pending_review', ['An earlier version said 10 roads; it was 12.']);
        $publishedAt = $post->published_at->toIso8601String();
        $slug = $post->slug;

        $rev->apply();
        $post->refresh();

        $this->assertSame($slug, $post->slug);
        $this->assertSame($publishedAt, $post->published_at->toIso8601String());
        $this->assertSame('img/a.jpg', $post->featured_image);
        $this->assertSame('County reopens 12 roads after weekend storm', $post->title);
        $this->assertStringContainsString('class="correction-note"', $post->body);
        $this->assertStringContainsString('it was 12', $post->body);
        $this->assertSame('applied', $rev->fresh()->status);
    }

    public function test_rollback_restores_the_original_exactly(): void
    {
        [$post, $rev] = $this->publishedPostWithRevision();
        $originalBody = $post->body;
        $rev->apply();
        $rev->fresh()->rollback();
        $post->refresh();

        $this->assertSame($originalBody, $post->body);
        $this->assertSame('County reopens flooded roads after storm', $post->title);
        $this->assertSame('rolled_back', $rev->fresh()->status);
    }

    public function test_unverified_revisions_can_never_be_applied(): void
    {
        foreach (['needs_rewrite', 'needs_research', 'rejected'] as $status) {
            [, $rev] = $this->publishedPostWithRevision($status);
            try {
                $rev->apply();
                $this->fail("A {$status} revision was applied.");
            } catch (\RuntimeException) {
                $this->assertSame($status, $rev->fresh()->status);
            }
        }
    }

    // ── Source quality and corrections (policy v2) ──────────────────────────

    public function test_scraped_copies_do_not_count_as_independent_sources(): void
    {
        $original = ['title' => 'Suspected Florida Publix slasher walked free from court months earlier', 'text' => 'Fox story text one two three'];
        $this->assertTrue(\App\Services\WebResearcher::isCopyOf('Suspected Florida Publix slasher walked free from court months earlier - 10bmnews.com', 'different scraped wrapper text', [$original]));
        $this->assertTrue(\App\Services\WebResearcher::isCopyOf('Suspected Florida Publix slasher walked free from court months earlier | CommsTrader', 'x', [$original]));
        $this->assertFalse(\App\Services\WebResearcher::isCopyOf('Punta Gorda man accused of stabbing shopper at Publix', 'local station report', [$original]));
    }

    public function test_blogs_are_not_sources(): void
    {
        $r = app(\App\Services\WebResearcher::class);
        $this->assertTrue($r->blockedHost('justinthenickofcrime.substack.com'));
        $this->assertTrue($r->blockedHost('someone.medium.com'));
        $this->assertFalse($r->blockedHost('apnews.com'));
        $this->assertSame('Los Angeles Times', $r->publisherName('latimes.com'));
        $this->assertNotSame('https://www.mysuncoast.com', $r->publisherName('mysuncoast.com', 'https://www.mysuncoast.com'));
    }

    public function test_only_verified_corrections_reach_readers(): void
    {
        $originalCheck = ['issues' => [
            ['claim' => 'teacher, 34', 'problem' => 'sources say 25', 'severity' => 'critical', 'fix' => 'age 25'],     // #1 correctable
            ['claim' => 'meal train', 'problem' => 'not in sources', 'severity' => 'critical', 'fix' => ''],          // #2 unsupported → edit
            ['claim' => 'tone', 'problem' => 'loose paraphrase', 'severity' => 'minor', 'fix' => 'tighten'],          // #3 minor → edit
        ]];
        $claimed = [
            ['issue' => 1, 'text' => 'An earlier version said the teacher was 34; she was 25.'],
            ['issue' => 2, 'text' => 'Removed the meal-train detail.'],
            ['issue' => 3, 'text' => 'Tightened a paraphrase.'],
            ['issue' => 9, 'text' => 'Invented correction.'],
            ['issue' => 1, 'text' => 'Duplicate of #1.'],
        ];
        $this->assertSame(['An earlier version said the teacher was 34; she was 25.'], \App\Services\EditorialPipeline::verifiedCorrections($claimed, $originalCheck));
    }

    public function test_vague_attribution_is_blocked(): void
    {
        $post = $this->newsPost(['body' => $this->paragraphs(8) . '<p>The sources also said the weapon belonged to his mother.</p>']);
        $this->assertArrayHasKey('stock-phrase', PublishGate::failures($post));
    }

    public function test_stock_phrases_inside_real_quotes_are_allowed(): void
    {
        $quoted = $this->newsPost(['body' => $this->paragraphs(8) . '<p>House Democratic leaders called the search “deeply disturbing” and said it “raises serious questions” about the use of federal power.</p>']);
        $this->assertArrayNotHasKey('stock-phrase', PublishGate::failures($quoted));

        $ours = $this->newsPost(['body' => $this->paragraphs(8) . '<p>The search raises serious questions about the use of federal power.</p>']);
        $this->assertArrayHasKey('stock-phrase', PublishGate::failures($ours));
    }

    public function test_sources_published_or_updated_after_the_article_are_flagged(): void
    {
        $published = '2026-10-17T16:00:00+00:00';
        $marked = \App\Services\EditorialPipeline::markLaterSources([
            ['url' => 'a', 'published_at' => '2026-10-17T12:00:00+00:00'],                                   // before
            ['url' => 'b', 'published_at' => '2026-10-17T12:00:00+00:00', 'modified_at' => '2026-10-17T20:00:00+00:00'], // updated after
            ['url' => 'c', 'published_at' => '2026-10-17T19:00:00+00:00'],                                   // published after
            ['url' => 'd'],                                                                                  // unknown
        ], $published);
        $this->assertSame([false, true, true, false], array_column($marked, 'later'));
    }
}
