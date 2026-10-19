<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\FactChecker;
use App\Services\ResearchAnalyst;
use App\Services\SourcePacket;
use App\Services\WebResearcher;
use App\Support\AiModels;
use App\Support\ArticleSections;
use App\Support\EditorialPolicy;
use App\Support\ExactQuoteVerifier;
use App\Support\LeadCheck;
use App\Support\NumberUnitCheck;
use App\Support\PublishGate;
use App\Support\SourceRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for the original-source / quotation / units / chronology / opening-and-ending upgrade.
 * Fixtures mirror the two articles that exposed the problems (a Story of Hope piece built from aggregators
 * of one CBS Boston report, and a Rubio piece with statements from different dates and mixed units).
 */
class QualityUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function interview(): string
    {
        return 'FOXBORO, Mass. — Christina Caron said her 7-year-old son Tristan, who has autism, was sitting alone at Urban Air on Saturday, Oct. 3 when a group of teenagers invited him to play. '
            . '"We just wanted to include him and have a fun time playing around at Urban Air," Anna Hele told CBS Boston. '
            . 'Caron said the group played catch and hide-and-seek for about forty minutes before she posted on Facebook to find them and thank them. '
            . 'Urban Air management said it would invite the teenagers and Tristan back for a special day. ' . str_repeat('Staff at the park said the encounter spread quickly after the post. ', 6);
    }

    private function packets(): array
    {
        $cbs = ['name' => 'CBS Boston', 'url' => 'https://www.cbsnews.com/boston/news/foxboro-boy-autism-act-of-kindness-urban-air/', 'published_at' => '2026-10-06T14:00:00Z', 'text' => $this->interview()];
        return [
            $cbs,
            ['name' => 'Sunny Skyz', 'url' => 'https://www.sunnyskyz.com/good-news/6334/x', 'published_at' => '2026-10-07T10:00:00Z',
                'text' => 'Teens invite a 7-year-old boy with autism to play. "We just wanted to include him and have a fun time playing around at Urban Air," Anna Hele told WBZ-TV. The group played catch and hide-and-seek. ' . str_repeat('Filler about kindness. ', 12)],
            ['name' => 'Hoodline', 'url' => 'https://hoodline.com/2026/10/teens/', 'published_at' => '2026-10-08T10:00:00Z',
                'text' => 'Christina Caron said her 7-year-old son Tristan, who has autism, was sitting alone at Urban Air when a group of teenagers invited him to play. Caron said the group played catch and hide-and-seek for about forty minutes before she posted on Facebook to find them and thank them. ' . str_repeat('More local color. ', 12)],
            ['name' => 'WTN Global', 'url' => 'https://www.world-today-news.com/teens-include-boy/', 'published_at' => '2026-10-09T10:00:00Z',
                'text' => 'Urban Air management said it would invite the teenagers and Tristan back for a special day. Christina Caron said her 7-year-old son Tristan, who has autism, was sitting alone when a group of teenagers invited him to play. ' . str_repeat('Rewritten summary text. ', 12)],
        ];
    }

    // Test 1 ─────────────────────────────────────────────────────────────
    public function test_aggregator_copies_of_one_interview_are_not_independent_confirmation(): void
    {
        $rel = SourceRelations::analyze($this->packets());
        $this->assertTrue($rel[0]['origin'], 'CBS Boston is the original reporting');
        $this->assertSame('original', $rel[0]['relation']);
        foreach ([1, 2, 3] as $i) {
            $this->assertFalse($rel[$i]['origin'], "packet {$i} is derived");
            $this->assertSame($rel[0]['group'], $rel[$i]['group'], 'all of it is one piece of reporting');
        }
        $this->assertSame('CBS Boston', $rel[1]['derived_from_name'], 'Sunny Skyz credits WBZ-TV, i.e. the CBS Boston reporting');
        $this->assertContains($rel[1]['relation'], ['aggregator']);

        $prepared = SourcePacket::prepare($this->packets(), 'Urban Air teenagers autism');
        $this->assertSame(1, SourceRelations::independentCount($prepared));
        $this->assertFalse(SourcePacket::assess($prepared)['sufficient'], 'four websites, one report: not enough');
        $this->assertStringContainsString('only 1 independent', implode(' ', SourcePacket::assess($prepared)['reasons']));
        $this->assertStringContainsString('does NOT independently confirm', SourcePacket::render($prepared));

        // A genuinely independent report adds a second group.
        $independent = ['name' => 'Boston Globe', 'url' => 'https://www.bostonglobe.com/x', 'published_at' => '2026-10-07T09:00:00Z',
            'text' => 'Bellingham police and park staff described a quiet afternoon at the indoor park with several families present and no incidents of note reported to officers. ' . str_repeat('A distinct local account with its own wording and interviews. ', 12)];
        $withGlobe = SourcePacket::prepare(array_merge($this->packets(), [$independent]), 'Urban Air');
        $this->assertSame(2, SourceRelations::independentCount($withGlobe));
        $this->assertTrue(SourcePacket::assess($withGlobe)['sufficient']);
    }

    public function test_the_publishing_source_count_counts_independent_groups_not_sites(): void
    {
        $post = new Post(['sources' => [['url' => 'https://a.test/1', 'group' => 1], ['url' => 'https://b.test/2', 'group' => 1], ['url' => 'https://c.test/3', 'group' => 1]]]);
        $this->assertSame(1, PublishGate::distinctSourceCount($post));
        $legacy = new Post(['sources' => [['url' => 'https://a.test/1'], ['url' => 'https://b.test/2']]]);
        $this->assertSame(2, PublishGate::distinctSourceCount($legacy), 'records without relationship data still count hosts');
    }

    // Test 2 ─────────────────────────────────────────────────────────────
    public function test_the_event_date_is_recovered_from_the_original_report(): void
    {
        $prepared = SourcePacket::prepare($this->packets(), 'Urban Air');
        $dates = array_column($prepared[0]['dates_mentioned'], 'iso');
        $this->assertContains('2026-10-03', $dates, 'CBS states Saturday, Oct. 3');
        $this->assertSame([], array_filter($prepared[2]['dates_mentioned'], fn ($d) => $d['kind'] === 'stated'), 'the summary states no date');
        $render = SourcePacket::render($prepared);
        $this->assertStringContainsString('2026-10-03 = "Oct. 3"', $render);
        $this->assertStringContainsString('NOT the event date', $render);
        $this->assertContains('2026-10-03', array_column(SourcePacket::datesMentioned('It happened on Saturday.', '2026-10-06T14:00:00Z'), 'iso'), 'relative days resolve against the publication date');
    }

    // Test 3 ─────────────────────────────────────────────────────────────
    public function test_two_separate_remarks_joined_into_one_quotation_are_critical_and_block_publication(): void
    {
        $sources = [['name' => 'NY Post', 'url' => 'https://nypost.com/x', 'text' => 'Rubio told reporters in Uzbekistan: "There\'s been no pause." Asked about the war, he said later: "Zelensky should be focused on bringing an end to this war." ' . str_repeat('More reporting here. ', 20)]];
        $joined = '<p>Rubio told reporters in Uzbekistan, “There’s been no pause. Zelensky should be focused on bringing an end to this war.”</p>';
        $f = ExactQuoteVerifier::findings($joined, $sources);
        $this->assertSame('critical', $f[0]['severity']);
        $this->assertStringContainsString('joins separate remarks', $f[0]['problem']);

        $ok = '<p>Rubio said: “There’s been no pause.” He later said, “Zelensky should be focused on bringing an end to this war.”</p>';
        $this->assertSame([], ExactQuoteVerifier::findings($ok, $sources), 'separate quotations are fine');

        $verdict = $this->check($joined, $sources)['verdict'];
        $this->assertNotSame('ready', $verdict, 'a critical quotation error stops automatic publication');
    }

    // Test 4 ─────────────────────────────────────────────────────────────
    public function test_unit_discrepancies_are_flagged_and_never_converted(): void
    {
        $sources = [
            ['name' => 'NY Post', 'text' => 'Trump said Oct. 9 the United States would import 100,000 gallons of diesel fuel from Russia. ' . str_repeat('Filler. ', 30)],
            ['name' => 'Trade Press', 'text' => 'The shipment will bring 100,000 metric tons of diesel fuel into U.S. ports, traders said. ' . str_repeat('Filler. ', 30)],
        ];
        $conf = NumberUnitCheck::packetConflicts($sources);
        $this->assertNotEmpty($conf, 'gallons vs metric tons of diesel is an unresolved discrepancy');

        $f = NumberUnitCheck::findings('<p>The United States will import 100,000 gallons of diesel fuel.</p>', $sources);
        $this->assertSame('critical', $f[0]['severity']);
        $this->assertStringContainsString('Unresolved unit discrepancy', implode(' ', array_column($f, 'problem')));

        $swap = NumberUnitCheck::findings('<p>The shipment is 100,000 metric tons.</p>', [$sources[0]]);
        $this->assertStringContainsString('Unit mismatch', $swap[0]['problem']);
        $this->assertStringNotContainsString('converted to', implode(' ', array_column($swap, 'fix')));

        $invented = NumberUnitCheck::findings('<p>It will import 380,000 gallons of diesel.</p>', [$sources[0]]);
        $this->assertSame('critical', $invented[0]['severity']);
        $this->assertStringContainsString('No source states', $invented[0]['problem']);

        // The Rubio case: one report says "hundreds of thousands of gallons", the AP says "300,000 tons" of the same diesel.
        $rubio = [
            ['name' => 'New York Post', 'text' => 'Tensions rose after Trump’s Oct. 9 announcement that the US would import hundreds of thousands of gallons of diesel fuel to relieve prices. ' . str_repeat('Filler. ', 30)],
            ['name' => 'ABC30 (AP)', 'text' => 'Trump announced that Russia will immediately supply more than 300,000 tons of diesel, followed by an additional 500,000 tons in November. ' . str_repeat('Filler. ', 30)],
        ];
        $v = NumberUnitCheck::findings('<p>Trump announced the US would import hundreds of thousands of gallons of diesel fuel.</p>', $rubio);
        $this->assertSame('critical', $v[0]['severity']);
        $this->assertStringContainsString('Unresolved unit discrepancy', $v[0]['problem']);
        $this->assertSame([], NumberUnitCheck::findings('<p>The sanctions waiver covers Russian diesel.</p>', $rubio), 'no quantity, no finding');

        $rounding = NumberUnitCheck::findings('<p>About 5,200 gallons were spilled.</p>', [['text' => 'Officials said 5,000 gallons were spilled.']]);
        $this->assertSame('minor', $rounding[0]['severity'], 'rounding never blocks on its own');
        $this->assertSame([], NumberUnitCheck::findings('<p>It will import 100,000 gallons of diesel fuel.</p>', [$sources[0]]));
    }

    // Test 5 ─────────────────────────────────────────────────────────────
    public function test_statements_keep_their_occasion_and_date_through_research_writing_and_checking(): void
    {
        $block = ResearchAnalyst::writerBlock(['quotes' => [
            ['quote' => 'American-linked ships have been targeted', 'speaker' => 'Rubio', 'packet' => 2, 'occasion' => 'Fox News interview', 'date' => '2026-09-22'],
            ['quote' => 'There\'s been no pause.', 'speaker' => 'Rubio', 'packet' => 1, 'occasion' => 'press appearance in Uzbekistan', 'date' => '2026-10-19'],
        ]]);
        $this->assertStringContainsString('Fox News interview 2026-09-22', $block);
        $this->assertStringContainsString('press appearance in Uzbekistan 2026-10-19', $block);

        $sent = null;
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('json')->andReturnUsing(function ($stage, $system) use (&$sent) {
            $sent = $system;

            return ['claims_checked' => 3, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'ok'];
        });
        app(FactChecker::class)->check($ai, 'Title', '<p>Body.</p>', [['name' => 'A', 'url' => 'https://a.test', 'text' => str_repeat('word ', 80)]]);
        $this->assertStringContainsString('STATEMENT CHRONOLOGY', (string) $sent);
        $this->assertStringContainsString('NUMBERS AND UNITS', (string) $sent);
        $this->assertStringContainsString('ORIGINAL REPORTING AND INDEPENDENCE', (string) $sent);
        $this->assertStringContainsString('Compare every quantity WITH its unit', EditorialPolicy::text());
        $this->assertStringContainsString('Keep each statement with the occasion and date', EditorialPolicy::text());
    }

    // Test 6 ─────────────────────────────────────────────────────────────
    public function test_an_excerpt_that_repeats_the_lead_is_detected_but_shared_names_are_fine(): void
    {
        $body = '<p>Christina Caron used Facebook to identify the teenagers who played with her 7-year-old son Tristan at Urban Air in Bellingham. Urban Air said it plans to invite everyone back.</p><p>Later paragraph.</p>';
        $same = 'Christina Caron used Facebook to identify the teenagers who played with her 7-year-old son Tristan at Urban Air in Bellingham. Urban Air said it plans to invite everyone involved back.';
        $this->assertTrue(LeadCheck::excerptRepeatsLead($same, $body));

        // The published Urban Air article: the excerpt restates the lead's first sentences with a few words dropped.
        $leadAdv = '<p>Christina Caron used Facebook to identify the teenagers who played with her 7-year-old son Tristan at Urban Air Adventure Park in Bellingham, Massachusetts, after the group spent about 40 minutes including him in their games. Urban Air management also said it planned to invite everyone involved back for a special day.</p><p>More.</p>';
        $this->assertTrue(LeadCheck::excerptRepeatsLead('Christina Caron used Facebook to identify the teenagers who played with her 7-year-old son Tristan at Urban Air in Bellingham. Urban Air said it plans to invite everyone involved back for a special day.', $leadAdv));

        $different = 'A group of teenagers made room for a boy with autism at a Massachusetts play park, and his mother found them online to say thanks.';
        $this->assertFalse(LeadCheck::excerptRepeatsLead($different, $body));

        $post = new Post(['title' => 'Teens welcome boy', 'excerpt' => $same, 'body' => $body]);
        $this->assertArrayHasKey('excerpt-repeats-lead', PublishGate::originality($post));
        $post->excerpt = $different;
        $this->assertArrayNotHasKey('excerpt-repeats-lead', PublishGate::originality($post));
    }

    // Test 7 ─────────────────────────────────────────────────────────────
    public function test_a_human_interest_story_ending_on_background_is_flagged_and_a_follow_up_is_not(): void
    {
        $weak = '<p>Teens included Tristan in their games at the park.</p><p>His mother thanked them online.</p><p>Owner Chad Darling said the facility has operated for about eight years, making it a frequent backdrop for children making friends.</p>';
        $this->assertNotNull(LeadCheck::backgroundEnding($weak));
        $this->assertArrayHasKey('background-ending', PublishGate::originality(new Post(['title' => 'T', 'body' => $weak])));

        $good = '<p>Teens included Tristan in their games at the park.</p><p>His mother thanked them online.</p><p>Urban Air said it will invite the teenagers and Tristan back for a special day, and Caron said her son is looking forward to it.</p>';
        $this->assertNull(LeadCheck::backgroundEnding($good));
        $this->assertArrayNotHasKey('background-ending', PublishGate::originality(new Post(['title' => 'T', 'body' => $good])));
    }

    // Test 8 ─────────────────────────────────────────────────────────────
    public function test_key_takeaways_our_take_and_sources_sections_stay_off(): void
    {
        $this->assertFalse(ArticleSections::takeawaysEnabled());
        $this->assertFalse(ArticleSections::ourTakeEnabled());
        $this->assertFalse(ArticleSections::sourcesEnabled());
        $site = EditorialPolicy::siteConfiguration();
        $this->assertStringContainsString('Key Takeaways: OFF', $site);
        $this->assertStringContainsString('Our Take section in news: OFF', $site);
        $this->assertStringContainsString('Public Sources/References section: OFF', $site);

        $fails = PublishGate::originality(new Post(['title' => 'T', 'body' => '<p>One.</p><h2>Key Takeaways</h2><ul><li>x</li></ul><h2>Sources</h2><ul><li>AP</li></ul>']));
        $this->assertTrue((bool) array_intersect(['disabled-section', 'public-source-section'], array_keys($fails)));
    }

    // Test 9 ─────────────────────────────────────────────────────────────
    public function test_an_unresolved_critical_error_prevents_automatic_publication(): void
    {
        $cat = Category::create(['name' => 'World', 'slug' => 'world']);
        $post = Post::create(['title' => 'Rubio says Ukraine hit ships', 'slug' => 'r-' . uniqid(), 'category_id' => $cat->id, 'status' => 'draft',
            'body' => str_repeat('<p>Rubio told reporters the strikes hit American-linked ships and said talks continue as officials weigh a response this week.</p>', 40),
            'review_status' => 'needs_rewrite', 'fact_check' => ['verdict' => 'needs_rewrite', 'critical' => 1, 'claims_checked' => 8],
            'sources' => [['url' => 'https://a.test', 'group' => 1], ['url' => 'https://b.test', 'group' => 2], ['url' => 'https://c.test', 'group' => 3]],
            'meta_title' => 'M', 'meta_description' => 'D']);
        $this->assertArrayHasKey('not-verified', PublishGate::failures($post));
    }

    // Test 10 ────────────────────────────────────────────────────────────
    public function test_model_roles_and_brave_search_are_unchanged(): void
    {
        $this->assertSame('gpt-5.4-mini', AiModels::writer());
        $this->assertSame('gpt-5.4', AiModels::factCheck());
        $this->assertSame('gpt-5.4', AiModels::research());
        Setting::put('openai_model', 'gpt-5.4-mini');
        $this->assertSame('gpt-5.4-mini', AiModels::writer(), 'the writer is not replaced');
        $this->assertNotSame(AiModels::writer(), AiModels::factCheck(), 'fact-check never shares the writing model');

        // The fact-check call uses the fact-check model.
        $model = null;
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('json')->andReturnUsing(function ($stage, $system, $user, $props, $m = null) use (&$model) {
            $model = $m;

            return ['claims_checked' => 1, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'ok'];
        });
        app(FactChecker::class)->check($ai, 'T', '<p>B.</p>', [['name' => 'A', 'url' => 'https://a.test', 'text' => str_repeat('word ', 80)]]);
        $this->assertSame('gpt-5.4', $model);

        // Brave stays the provider; focused queries are built from the story text (headline search is kept).
        $text = 'Rubio said "We have seen strikes on energy vessels in the Black Sea and there have been complaints from American companies." Marco Rubio spoke in Uzbekistan. Trump said the U.S. would import 100,000 gallons of diesel fuel.';
        $q = WebResearcher::focusedQueries('Rubio says Ukraine hit ships', $text);
        $this->assertStringContainsString('We have seen strikes on energy vessels', $q[0]);
        $this->assertTrue((bool) collect($q)->first(fn ($x) => str_contains($x, 'Rubio') && str_contains($x, 'statement')), 'a people/place query');
        $this->assertTrue((bool) collect($q)->first(fn ($x) => str_contains($x, '100,000 gallons')), 'a query around the headline number');
    }

    private function check(string $body, array $sources): array
    {
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('json')->andReturn(['claims_checked' => 4, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'ok']);

        return app(FactChecker::class)->check($ai, 'Title', $body, $sources);
    }
}
