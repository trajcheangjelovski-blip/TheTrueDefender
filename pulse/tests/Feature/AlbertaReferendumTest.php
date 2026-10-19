<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRevision;
use App\Services\AiClient;
use App\Services\FactChecker;
use App\Services\ResearchAnalyst;
use App\Services\SourcePacket;
use App\Services\WebResearcher;
use App\Support\AiModels;
use App\Support\ArticleSections;
use App\Support\EditorialPolicy;
use App\Support\EventLifecycle;
use App\Support\ExactQuoteVerifier;
use App\Support\LeadCheck;
use App\Support\PoliticalCheck;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression case: "Albertans vote on non-binding separation question Monday" (Oct. 19, 2026): a referendum preview with polls of
 * different populations and measures, a non-binding question that could lead to a later binding one, petitions, a court ruling under
 * appeal, two Carney rallies on different days, and an event whose results become due the same evening.
 */
class AlbertaReferendumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'America/Edmonton'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function package(): array
    {
        return [
            'polls' => [
                ['organization' => 'Abacus Data', 'fieldwork' => 'Oct. 7-13, 2026', 'released' => 'Oct. 17, 2026', 'sample_size' => '1,021', 'population' => 'decided_voters',
                    'question' => 'How would you vote on the question: remain a province or begin the legal process toward a binding referendum?', 'options' => 'remain / begin the process',
                    'measure' => 'voting_intention', 'undecided_included' => false, 'weighted' => 'yes',
                    'results' => [['label' => 'Remain', 'percent' => '74%']], 'limitations' => 'online panel', 'source_kind' => 'news_report', 'packets' => [1]],
                ['organization' => 'Angus Reid Institute', 'fieldwork' => 'Oct. 13-15, 2026', 'released' => 'Oct. 16, 2026', 'sample_size' => '1,010', 'population' => 'adults',
                    'question' => 'Agree or disagree: "there will never be an end to the separatist movement in Alberta"', 'options' => 'agree / disagree',
                    'measure' => 'expectation', 'undecided_included' => true, 'weighted' => 'yes',
                    'results' => [['label' => 'Agree there will never be an end', 'percent' => '62%'], ['label' => 'Disagree', 'percent' => '24%']],
                    'limitations' => 'online panel of Canadian adults who live in Alberta', 'source_kind' => 'primary_release', 'packets' => [2]],
            ],
            'legal' => [
                ['layer' => 'voters_decide_now', 'status' => 'non_binding_referendum', 'statement' => 'The ballot question is non-binding.', 'proceedings' => 'none', 'source_kind' => 'official_document', 'packets' => [3]],
                ['layer' => 'government_intends', 'status' => 'government_commitment', 'statement' => 'If the option to begin the process wins, the government says it would start the legal steps toward a later binding referendum.', 'proceedings' => 'none', 'source_kind' => 'official_document', 'packets' => [3]],
                ['layer' => 'law_permits_or_requires', 'status' => 'court_ruling', 'statement' => 'A court ruled a binding separation referendum could not proceed without consultation with First Nations; the ruling is under appeal.', 'proceedings' => 'appealed', 'source_kind' => 'court_decision', 'packets' => [4]],
            ],
            'petitions' => [
                ['organization' => 'Stay Free Alberta', 'initiative' => 'separation referendum petition', 'signatures_submitted' => '279,337', 'signatures_verified' => '222,597', 'status' => 'verified',
                    'dates' => 'counted July 6-23, 2026', 'authority' => 'Elections Alberta', 'packets' => [4]],
            ],
            'appearances' => [
                ['person' => 'Mark Carney', 'kind' => 'rally', 'date' => '2026-10-15', 'location' => 'Calgary', 'verified_by' => 'news_only', 'quote_excerpt' => '', 'packets' => [5]],
                ['person' => 'Mark Carney', 'kind' => 'speech', 'date' => '2026-10-16', 'location' => 'Edmonton', 'verified_by' => 'official_record',
                    'quote_excerpt' => 'This referendum comes at the worst possible time.', 'packets' => [6]],
            ],
            'lifecycle' => ['event' => 'Alberta referendum', 'scheduled_at' => '2026-10-19 09:00 America/Edmonton', 'stage' => 'during', 'results_expected_at' => '2026-10-19 20:00 America/Edmonton',
                'official_results_at' => '', 'certification' => 'Elections Alberta publishes unofficial results as counted', 'article_type' => 'preview', 'notes' => 'polls close 8 p.m. MT'],
            'reconciled' => [
                ['discrepancy' => 'Reports put Carney\'s remark on Thursday or Friday.', 'kind' => 'different_event', 'resolution' => 'Calgary rally Oct. 15; the quoted remark is from the Edmonton speech on Oct. 16 (official transcript).', 'resolved_by' => 'pm.gc.ca transcript', 'packets' => [6]],
            ],
        ];
    }

    private function ballotQuestion(): string
    {
        return 'Should Alberta remain a province of Canada or should the Government of Alberta commence the legal process required under the Canadian Constitution to hold a binding provincial referendum on whether or not Alberta should separate from Canada?';
    }

    // 1: find the official ballot question and primary sources ──────────────────────────────────
    public function test_official_and_polling_primary_sources_are_searched_and_the_ballot_question_is_verified_verbatim(): void
    {
        $q = WebResearcher::officialQueries('Premier Danielle Smith, Elections Alberta, Prime Minister Mark Carney, an Abacus Data poll, the Angus Reid Institute and a court ruling under appeal in the Alberta referendum');
        $joined = implode(' | ', $q);
        $this->assertCount(4, $q);
        $this->assertStringContainsString('site:pm.gc.ca', $joined);
        $this->assertStringContainsString('site:elections.ab.ca', $joined);

        foreach (['https://www.pm.gc.ca/en/news/speeches/2026/10/16/x', 'https://www.elections.ab.ca/elections/referendum/', 'https://angusreid.org/alberta-referendum-x/', 'https://www.alberta.ca/x', 'https://www.canlii.org/en/ab/abkb/x'] as $u) {
            $this->assertSame('primary', SourcePacket::kind(['url' => $u]), $u);
        }

        $sources = [['name' => 'Elections Alberta', 'text' => 'Question 10: ' . $this->ballotQuestion() . ' ' . str_repeat('Voting information. ', 20)]];
        $this->assertSame([], ExactQuoteVerifier::findings('<p>The ballot asks: “' . $this->ballotQuestion() . '”</p>', $sources));
        $changed = str_replace('commence the legal process', 'start the process', $this->ballotQuestion());
        $this->assertNotEmpty(ExactQuoteVerifier::findings('<p>The ballot asks: “' . $changed . '”</p>', $sources), 'a reworded ballot question is not quoted');
    }

    // 2 and 9: legal status and next steps ─────────────────────────────────────────────────────────
    public function test_a_non_binding_vote_is_never_written_as_changing_legal_status_by_itself(): void
    {
        $pkg = $this->package();
        $bad = '<p>If the option wins, Alberta will separate from Canada.</p>';
        $f = PoliticalCheck::findings($bad, $pkg);
        $this->assertSame('critical', $f[0]['severity']);
        $this->assertStringContainsString('non-binding', $f[0]['problem']);

        $good = '<p>The question is non-binding. If the option to begin the process wins, the government says it would start the legal steps toward a later binding referendum; a court has said a binding vote cannot proceed without consultation with First Nations, and that ruling is under appeal.</p>';
        $this->assertSame([], PoliticalCheck::findings($good, $pkg));

        $block = ResearchAnalyst::writerBlock($pkg + ['event' => ['title' => 'x']]);
        foreach (['voters_decide_now', 'government_intends', 'law_permits_or_requires', 'proceedings: appealed'] as $needle) {
            $this->assertStringContainsString($needle, $block);
        }
    }

    // 3, 4, 5: polls ───────────────────────────────────────────────────────────────────────────────
    public function test_decided_voter_percentages_and_expectations_are_not_confused_with_support_or_with_all_residents(): void
    {
        $pkg = $this->package();
        $this->assertSame([], PoliticalCheck::findings('<p>An Abacus Data poll of 1,021 respondents found 74% of decided voters favor remaining in Canada.</p>', $pkg));

        $population = PoliticalCheck::findings('<p>A poll found 74% of Albertans favor remaining in Canada.</p>', $pkg);
        $this->assertSame('critical', $population[0]['severity']);
        $this->assertStringContainsString('decided voters', $population[0]['problem']);

        $this->assertSame([], PoliticalCheck::findings('<p>The Angus Reid Institute found 62% of Albertans believe there will never be an end to the separatist movement.</p>', $pkg));
        $measure = PoliticalCheck::findings('<p>The Angus Reid Institute found 62% of Albertans support leaving Canada.</p>', $pkg);
        $this->assertSame('critical', $measure[0]['severity']);
        $this->assertStringContainsString('EXPECT', $measure[0]['problem']);

        $reverse = PoliticalCheck::findings('<p>Abacus Data found 74% of decided voters expect Alberta to remain.</p>', $pkg);
        $this->assertSame('critical', $reverse[0]['severity'], 'a voting intention described as an expectation');

        $block = ResearchAnalyst::developingBlock($pkg);
        $this->assertStringContainsString('population: decided_voters', $block);
        $this->assertStringContainsString('fieldwork Oct. 13-15, 2026', $block);
        $this->assertStringContainsString('n=1,010', $block);
        $this->assertStringContainsString('question: "Agree or disagree', $block);
    }

    // 6 and 7: speech dates, separate appearances, false conflicts ─────────────────────────────────
    public function test_an_official_record_settles_the_speech_date_and_separate_appearances_are_not_a_conflict(): void
    {
        $pkg = $this->package();
        $notes = implode(' ', ResearchAnalyst::appearanceNotes($pkg));
        $this->assertStringContainsString('separate appearances', $notes);
        $this->assertStringContainsString('settled by an official record', $notes);

        $needless = '<p>Prime Minister Mark Carney criticized the vote at a rally in Edmonton, though the reports differ on whether the event took place Thursday or Friday.</p>';
        $f = PoliticalCheck::findings($needless, $pkg);
        $this->assertSame('minor', $f[0]['severity']);
        $this->assertStringContainsString('official record or transcript', $f[0]['problem']);

        $good = '<p>At a Forever Canadian rally in Edmonton on Friday, Oct. 16, Prime Minister Mark Carney said, “This referendum comes at the worst possible time.” He had spoken at a rally in Calgary the day before.</p>';
        $this->assertSame([], PoliticalCheck::findings($good, $pkg));
        $this->assertStringContainsString('RECONCILED DISCREPANCIES', ResearchAnalyst::developingBlock($pkg));

        // A date that contradicts the official transcript is wrong, not merely uncertain.
        $wrong = PoliticalCheck::findings('<p>Prime Minister Mark Carney urged Albertans to vote against separation at a rally in Edmonton on Thursday.</p>', $pkg);
        $this->assertSame('critical', $wrong[0]['severity']);
        $this->assertStringContainsString('Friday, October 16, 2026', $wrong[0]['problem']);

        // Punctuation inside a quotation, such as the ballot question's comma, must match the source.
        $sources = [['name' => 'Elections Alberta', 'text' => 'Question 10: Should Alberta remain a province of Canada, or should the Government of Alberta commence the legal process required under the Canadian Constitution? ' . str_repeat('More. ', 20)]];
        $punct = ExactQuoteVerifier::findings('<p>The ballot asks: “Should Alberta remain a province of Canada or should the Government of Alberta commence the legal process required under the Canadian Constitution?”</p>', $sources);
        $this->assertSame('minor', $punct[0]['severity']);
        $this->assertStringContainsString('punctuation', $punct[0]['problem']);

        $noRecord = $pkg;
        $noRecord['appearances'][1]['verified_by'] = 'news_only';
        $this->assertSame([], PoliticalCheck::findings($needless, $noRecord), 'with no official record the uncertainty is real and may be stated');
    }

    // 8: petitions ─────────────────────────────────────────────────────────────────────────────────
    public function test_submitted_and_verified_petition_signatures_are_kept_apart(): void
    {
        $pkg = $this->package();
        $bad = PoliticalCheck::findings('<p>The pro-separation petition gathered about 280,000 verified signatures.</p>', $pkg);
        $this->assertSame('critical', $bad[0]['severity']);
        $this->assertStringContainsString('SUBMITTED', $bad[0]['problem']);

        $this->assertSame([], PoliticalCheck::findings('<p>Elections Alberta said it verified 222,597 of the 279,337 signatures submitted for the separation petition.</p>', $pkg));
        $this->assertStringContainsString('submitted 279,337, verified 222,597', ResearchAnalyst::developingBlock($pkg));
    }

    // 10 and 11: event lifecycle ──────────────────────────────────────────────────────────────────
    public function test_results_timing_and_outdated_previews_are_recognized_and_flagged_not_rewritten(): void
    {
        $pkg = $this->package();
        $this->assertFalse(EventLifecycle::outdated($pkg), 'noon on voting day: the preview is still current');
        $this->assertStringContainsString('during the event', EventLifecycle::stageNow($pkg));

        Carbon::setTestNow(Carbon::parse('2026-10-19 21:00:00', 'America/Edmonton'));
        $this->assertTrue(EventLifecycle::outdated($pkg), 'after the polls close the preview is outdated');
        $this->assertStringContainsString('results are due', EventLifecycle::stageNow($pkg));

        $results = $pkg;
        $results['lifecycle']['article_type'] = 'results_report';
        $results['lifecycle']['stage'] = 'preliminary_results';
        $this->assertFalse(EventLifecycle::outdated($results), 'a results report is not an outdated preview');

        // The hourly command flags the published preview for an editor and leaves the article untouched.
        $post = Post::create(['title' => 'Albertans vote Monday', 'slug' => 'ab-vote', 'status' => 'published', 'published_at' => Carbon::parse('2026-10-19 10:00:00', 'America/Edmonton'),
            'body' => '<p>Voting began Monday.</p>', 'research_package' => $pkg]);
        $before = [$post->body, $post->published_at->toIso8601String()];

        $this->artisan('posts:flag-outdated-previews', ['--dry' => true])->assertSuccessful();
        $this->assertSame(0, PostRevision::count());
        $this->artisan('posts:flag-outdated-previews')->assertSuccessful();
        $this->artisan('posts:flag-outdated-previews')->assertSuccessful();   // idempotent
        $rev = PostRevision::firstOrFail();
        $this->assertSame(1, PostRevision::count());
        $this->assertSame('needs_research', $rev->status);
        $this->assertSame('event_passed', $rev->change_log['flag']);
        $this->assertStringContainsString('clearly dated', $rev->change_log['proposal']);
        $this->assertSame($before, [$post->fresh()->body, $post->fresh()->published_at->toIso8601String()]);
        $this->assertSame('published', $post->fresh()->status);
        $this->assertStringContainsString('Never present a completed event as ongoing', ResearchAnalyst::developingBlock($pkg));
    }

    // 10: repetitive introduction and conclusion ───────────────────────────────────────────────────
    private function articleBody(string $ending): string
    {
        return '<p>Voting began Monday in Alberta on a non-binding referendum question that asks whether the province should remain in Canada or whether the provincial government should begin the legal process for a later binding referendum on separation, BBC News reported.</p>'
            . '<p>The ballot question reads: “' . $this->ballotQuestion() . '”</p>'
            . '<p>Premier Danielle Smith opposes separation and has said she will vote to keep Alberta in Canada.</p>'
            . '<p>A court said a binding separation referendum could not proceed without consultation with First Nations; the government is appealing that ruling.</p>'
            . '<p>' . $ending . '</p>';
    }

    public function test_the_ending_and_excerpt_must_not_repeat_the_opening(): void
    {
        $title = 'Albertans vote on non-binding separation question Monday';
        $repeat = 'Alberta’s vote began Monday and will determine whether the province takes the first formal step toward a possible later referendum, even as the current ballot itself does not decide independence.';
        $this->assertNotNull(LeadCheck::endingRepeatsOpening($title, $this->articleBody($repeat)));
        $this->assertArrayHasKey('ending-repeats-opening', PublishGate::originality(new Post(['title' => $title, 'body' => $this->articleBody($repeat)])));

        $next = 'Elections Alberta says ballots are counted by hand and unofficial results are published as returning officers report them, with the count required to finish within 48 hours of the polls closing.';
        $this->assertNull(LeadCheck::endingRepeatsOpening($title, $this->articleBody($next)));
        $this->assertArrayNotHasKey('ending-repeats-opening', PublishGate::originality(new Post(['title' => $title, 'body' => $this->articleBody($next)])));

        $excerpt = 'Voting began Monday in Alberta on a non-binding referendum question that could lead to a later binding vote on separation from Canada.';
        $this->assertTrue(LeadCheck::excerptRepeatsLead($excerpt, $this->articleBody($next)), 'the published excerpt restates the lead');
        $this->assertFalse(LeadCheck::excerptRepeatsLead('The ballot does not make Alberta independent; a court has said a binding vote needs First Nations consultation, and that ruling is on appeal.', $this->articleBody($next)));
    }

    // fact-check stage, existing rules ─────────────────────────────────────────────────────────────
    public function test_the_fact_checker_gets_the_political_rules_and_the_package_and_critical_findings_hold_the_story(): void
    {
        $sent = ['system' => null, 'user' => null, 'model' => null];
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('json')->andReturnUsing(function ($stage, $system, $user, $props, $model = null) use (&$sent) {
            $sent = ['system' => $system, 'user' => $user, 'model' => $model];

            return ['claims_checked' => 5, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'ok'];
        });
        $pkg = $this->package() + ['event' => ['title' => 'Alberta votes', 'what_happened' => 'x', 'who' => [], 'where' => 'Alberta', 'event_date' => '2026-10-19'], 'latest_development' => ['date' => '2026-10-19', 'summary' => 'voting'], 'newsworthiness' => 'new_event'];
        $evidence = [['name' => 'BBC News', 'url' => 'https://www.bbc.com/x', 'text' => 'Voting began Monday in Alberta on a non-binding referendum question. Premier Danielle Smith opposes separation. ' . str_repeat('Further reporting. ', 45)]];

        $ok = app(FactChecker::class)->check($ai, 'T', '<p>Voting began Monday in Alberta on a non-binding referendum question.</p>', $evidence, null, false, $pkg);
        $this->assertSame('gpt-5.4', $sent['model']);
        foreach (['POLLS:', 'LEGAL STATUS:', 'PETITIONS:', 'SPEECH DATES AND FALSE CONFLICTS:', 'EVENT LIFECYCLE:'] as $rule) {
            $this->assertStringContainsString($rule, (string) $sent['system']);
        }
        $this->assertStringContainsString('POLLS (state each percentage', (string) $sent['user']);
        $this->assertStringContainsString('EVENT LIFECYCLE: Alberta referendum', (string) $sent['user']);
        $this->assertSame('ready', $ok['verdict']);

        $held = app(FactChecker::class)->check($ai, 'T', '<p>A poll found 74% of Albertans favor remaining in Canada.</p>', $evidence, null, false, $pkg);
        $this->assertNotSame('ready', $held['verdict'], 'a decided-voter figure given for all Albertans is held, not published');
    }

    public function test_existing_rules_models_and_disabled_sections_are_unchanged(): void
    {
        $this->assertSame('gpt-5.4-mini', AiModels::writer());
        $this->assertSame('gpt-5.4', AiModels::factCheck());
        $this->assertSame('gpt-5.4', AiModels::research());
        $this->assertFalse(ArticleSections::takeawaysEnabled());
        $this->assertFalse(ArticleSections::ourTakeEnabled());
        $this->assertFalse(ArticleSections::sourcesEnabled());

        $policy = EditorialPolicy::text();
        foreach (['consult the', 'Report polls precisely', 'Keep three layers apart', 'Petitions: say which organization', 'Scheduled events (votes, elections, rulings)', 'Do not end by repeating the headline', 'look for the primary record'] as $new) {
            $this->assertStringContainsString($new, $policy);
        }
        foreach (['Developing news changes through the day', 'Compare every quantity WITH its unit', 'Find and credit the ORIGINAL reporting', 'Never combine similar people'] as $kept) {
            $this->assertStringContainsString($kept, $policy, 'earlier rules are still there');
        }
    }
}
