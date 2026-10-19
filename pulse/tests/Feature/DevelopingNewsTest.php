<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Services\AiClient;
use App\Services\FactChecker;
use App\Services\ResearchAnalyst;
use App\Services\SourcePacket;
use App\Services\WebResearcher;
use App\Support\AiModels;
use App\Support\ArticleSections;
use App\Support\EditorialPolicy;
use App\Support\ExactQuoteVerifier;
use App\Support\FigureCheck;
use App\Support\LeadCheck;
use App\Support\LinkQuality;
use App\Support\NumberUnitCheck;
use App\Support\PublishGate;
use App\Support\SourceRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression case: "Zelenskyy says Ukraine got no warning before Russian strike on power grid" (Oct. 18-19, 2026).
 * A developing story with changing damage figures, two waves of one attack, official claims from several parties,
 * quotations from two speakers and an ending about a separate drone attack.
 */
class DevelopingNewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function package(): array
    {
        return [
            'figures' => [
                ['label' => 'energy infrastructure facilities damaged', 'value' => 'more than 10', 'number' => '10', 'unit_type' => 'facilities', 'scope' => 'across Ukraine',
                    'as_of' => 'Oct. 19 morning', 'asserted_by' => 'Zelenskyy (NBC)', 'packets' => [3], 'status' => 'superseded', 'relation' => 'first_report', 'note' => ''],
                ['label' => 'substations and other critical facilities hit', 'value' => '28', 'number' => '28', 'unit_type' => 'facilities', 'scope' => 'across Ukraine',
                    'as_of' => 'Oct. 19 afternoon', 'asserted_by' => 'Zelenskyy (NY Post)', 'packets' => [1], 'status' => 'current', 'relation' => 'supersedes', 'note' => ''],
                ['label' => 'people without electricity, heat or water', 'value' => 'more than half a million', 'number' => '500000', 'unit_type' => 'people', 'scope' => 'across Ukraine',
                    'as_of' => 'Oct. 19 afternoon', 'asserted_by' => 'Zelenskyy', 'packets' => [1], 'status' => 'current', 'relation' => 'first_report', 'note' => ''],
                ['label' => 'ballistic missiles at Kyiv and the region', 'value' => '16', 'number' => '16', 'unit_type' => 'missiles', 'scope' => 'Kyiv and surrounding region',
                    'as_of' => 'overnight', 'asserted_by' => 'Zelenskyy', 'packets' => [1], 'status' => 'current', 'relation' => 'different_scope', 'note' => ''],
            ],
            'phases' => [
                ['label' => 'First wave (about 11 p.m. Sunday)', 'order' => 1, 'began' => 'Oct. 18, about 11 p.m.', 'note' => 'power plants in Kyiv hit', 'statements' => [
                    ['said_by' => 'Zelenskyy', 'statement' => 'no warning from partners', 'packet' => 1, 'warned' => 'no'],
                    ['said_by' => 'Yurii Ihnat', 'statement' => 'Ukraine had not been warned before the first wave', 'packet' => 2, 'warned' => 'no'],
                ]],
                ['label' => 'Second wave (about 3 a.m. Monday)', 'order' => 2, 'began' => 'Oct. 19, about 3 a.m.', 'note' => '', 'statements' => [
                    ['said_by' => 'Yurii Ihnat (Guardian)', 'statement' => 'a warning was received before the second strike', 'packet' => 2, 'warned' => 'yes'],
                    ['said_by' => 'NY Post / LA Times', 'statement' => 'information arrived too late for sirens', 'packet' => 1, 'warned' => 'late'],
                ]],
            ],
            'claims' => [
                ['claim' => 'the strike hit a data center, a logistics hub and a locomotive depot (military targets)', 'asserted_by' => 'Russian Defense Ministry', 'corroborated_independently' => false, 'packets' => [1]],
                ['claim' => 'a software problem involving the early-warning system a few nights ago', 'asserted_by' => 'Marco Rubio', 'corroborated_independently' => false, 'packets' => [2]],
            ],
            'timeline' => [
                ['kind' => 'event', 'at' => '2026-10-18 23:00', 'what' => 'first wave hits Kyiv power plants', 'packets' => [2]],
                ['kind' => 'warning', 'at' => '2026-10-19 03:00', 'what' => 'information about the second wave arrives', 'packets' => [1]],
                ['kind' => 'official_statement', 'at' => '2026-10-19 morning', 'what' => 'Zelenskyy posts that there was no warning', 'packets' => [1]],
                ['kind' => 'source_update', 'at' => '2026-10-19 12:40', 'what' => 'NY Post update with the 28-facility figure', 'packets' => [1]],
            ],
        ];
    }

    // 1: Different damage figures are evaluated in their proper chronology ─────────────────
    public function test_different_damage_figures_are_reconciled_by_time_scope_and_category_not_called_contradictions(): void
    {
        $notes = ResearchAnalyst::figureNotes($this->package());
        $this->assertCount(1, $notes, 'only the two facility counts are the same category');
        $this->assertSame('supersedes', $notes[0]['status']);
        $this->assertStringContainsString('not a contradiction', $notes[0]['why']);

        // different scope: compatible
        $p = $this->package();
        $p['figures'][1]['as_of'] = $p['figures'][0]['as_of'];
        $p['figures'][1]['scope'] = 'Kyiv region only';
        $this->assertSame('compatible', ResearchAnalyst::figureNotes($p)[0]['status']);
        // same scope, same time, different values, different issuers: a genuine disagreement to attribute
        $p['figures'][1]['scope'] = $p['figures'][0]['scope'];
        $this->assertSame('conflict', ResearchAnalyst::figureNotes($p)[0]['status']);
        // time of one figure unknown: never called a conflict
        $p['figures'][1]['as_of'] = '';
        $this->assertSame('unclear', ResearchAnalyst::figureNotes($p)[0]['status']);

        $block = ResearchAnalyst::writerBlock($this->package() + ['event' => ['title' => 'x']]);
        $this->assertStringContainsString('HOW THE FIGURES RELATE', $block);
        $this->assertStringContainsString('later one supersedes or supplements', $block);
    }

    public function test_a_superseded_or_unverified_figure_is_critical_only_when_stated_as_current(): void
    {
        $pkg = $this->package();
        $ok = '<p>NBC News reported earlier that more than 10 energy facilities were damaged; Zelenskyy later said 28 substations and other facilities were hit.</p>';
        $this->assertSame([], FigureCheck::findings($ok, $pkg));

        $bad = '<p>The strikes damaged more than 10 energy infrastructure facilities across the country.</p>';
        $f = FigureCheck::findings($bad, $pkg);
        $this->assertSame('critical', $f[0]['severity']);
        $this->assertStringContainsString('superseded', $f[0]['problem']);

        $pkg['figures'][2]['status'] = 'unverified';
        $u = FigureCheck::findings('<p>More than 500,000 people lost heat.</p>', $pkg);
        $this->assertSame('critical', $u[0]['severity']);
    }

    // 2: Different measurement units are not confused ───────────────────────────────────────
    public function test_people_households_customers_facilities_and_missiles_are_not_interchangeable(): void
    {
        $sources = [['name' => 'NY Post', 'text' => 'Zelenskyy said the barrage struck 28 substations and other critical facilities, leaving more than half a million people without electricity. Russia fired 16 ballistic missiles at Kyiv. ' . str_repeat('Filler. ', 30)]];

        $this->assertSame([], NumberUnitCheck::findings('<p>The barrage struck 28 substations and left more than half a million people without power; 16 ballistic missiles targeted Kyiv.</p>', $sources));

        $households = NumberUnitCheck::findings('<p>More than a half-million households lost power.</p>', $sources);
        $this->assertSame('critical', $households[0]['severity']);
        $this->assertStringContainsString('Unit mismatch', $households[0]['problem']);

        $people = NumberUnitCheck::findings('<p>The barrage killed 28 people.</p>', $sources);
        $this->assertSame('critical', $people[0]['severity'], '28 facilities is not 28 people');

        $drones = NumberUnitCheck::findings('<p>Russia fired 16 drones at Kyiv.</p>', $sources);
        $this->assertSame('critical', $drones[0]['severity'], '16 missiles is not 16 drones');
    }

    // 3 and 4: first and second wave; compatible reports versus genuine contradictions ─────────
    public function test_first_wave_and_second_wave_warnings_are_treated_separately(): void
    {
        $pkg = $this->package();
        $this->assertSame([], ResearchAnalyst::phaseConflicts($pkg), 'no warning before wave one and a late warning before wave two are compatible');

        $block = ResearchAnalyst::developingBlock($pkg);
        $this->assertStringContainsString('First wave', $block);
        $this->assertStringContainsString('Second wave', $block);
        $this->assertStringContainsString('too late for sirens', $block);
        $this->assertStringNotContainsString('GENUINE DISAGREEMENT', $block);

        // The same stage described two opposite ways is a real conflict and is reported as one.
        $pkg['phases'][0]['statements'][] = ['said_by' => 'A ministry spokesman', 'statement' => 'a warning was sent before the first wave', 'packet' => 3, 'warned' => 'yes'];
        $c = ResearchAnalyst::phaseConflicts($pkg);
        $this->assertCount(1, $c);
        $this->assertStringContainsString('First wave', $c[0]['phase']);
        $this->assertStringContainsString('GENUINE DISAGREEMENT', ResearchAnalyst::developingBlock($pkg));
    }

    // 5 and 6: quotations, speakers, attribution of official claims ───────────────────────────
    public function test_quotations_stay_continuous_and_official_claims_stay_attributed(): void
    {
        $sources = [
            ['name' => 'The Guardian', 'text' => 'Marco Rubio said: “If it happened last night, I don’t know exactly. I know a few nights ago there was a software problem involving their early warning system.” ' . str_repeat('More reporting. ', 20)],
            ['name' => 'NBC News', 'text' => 'Zelenskyy wrote: “Once again, Russia launched a ballistic missile attack in multiple waves throughout the night, and once again, there was no warning from our partners.” Later in the post he wrote: “Ukraine never wanted this war.” ' . str_repeat('More reporting. ', 20)],
        ];
        $ok = '<p>The Guardian quoted Rubio as saying, “If it happened last night, I don’t know exactly. I know a few nights ago there was a software problem involving their early warning system.”</p>';
        $this->assertSame([], ExactQuoteVerifier::findings($ok, $sources));

        $joined = '<p>Zelenskyy wrote, “once again, there was no warning from our partners. Ukraine never wanted this war.”</p>';
        $f = ExactQuoteVerifier::findings($joined, $sources);
        $this->assertSame('critical', $f[0]['severity']);
        $this->assertStringContainsString('joins separate remarks', $f[0]['problem']);

        $wrongSpeaker = '<p>Zelenskyy said, “If it happened last night, I don’t know exactly. I know a few nights ago there was a software problem involving their early warning system.”</p>';
        $this->assertNotEmpty(array_filter(ExactQuoteVerifier::findings($wrongSpeaker, $sources), fn ($x) => $x['severity'] === 'minor'), 'a real quotation credited to the wrong person is flagged');

        $block = ResearchAnalyst::developingBlock($this->package());
        $this->assertStringContainsString('OFFICIAL CLAIMS', $block);
        $this->assertStringContainsString('Russian Defense Ministry: the strike hit a data center', $block);
        $this->assertStringContainsString('[not independently corroborated]', $block);
        $this->assertStringContainsString('Marco Rubio: a software problem', $block);
    }

    // 7: sources evaluated for original reporting and independence ─────────────────────────────
    public function test_sources_are_classified_for_originality_and_official_hosts_are_primary(): void
    {
        $mk = fn ($name, $url, $text) => ['name' => $name, 'url' => $url, 'published_at' => '2026-10-19T14:00:00Z',
            'text' => $text . ' ' . implode(' ', array_map(fn ($i) => "{$name} correspondent {$i} described the scene {$i} with distinct detail {$name}{$i} gathered on the ground.", range(1, 8)))];
        $packets = [
            $mk('The Guardian', 'https://www.theguardian.com/world/2026/oct/19/x', 'Ukrainian Air Force spokesperson Yurii Ihnat told Ukrainian television that no warning had come before the first wave of strikes that hit power plants in Kyiv about 11 p.m.'),
            $mk('NBC News', 'https://www.nbcnews.com/world/ukraine/y', 'Officials in Kyiv said a 21-year-old woman was killed in the capital as the Russian Defense Ministry listed a data center and a locomotive depot among its targets.'),
            $mk('Los Angeles Times', 'https://www.latimes.com/world-nation/z', 'Information about the second wave arrived too late for air raid sirens, the Los Angeles Times reported after speaking with regional authorities.'),
            ['name' => 'Newsbreak', 'url' => 'https://www.newsbreak.com/n', 'published_at' => '2026-10-19T17:00:00Z',
                'text' => 'Information about the second wave arrived too late for air raid sirens, the Los Angeles Times reported after speaking with regional authorities. ' . str_repeat('Summary of coverage. ', 12)],
        ];
        $rel = SourceRelations::analyze($packets);
        $this->assertTrue($rel[0]['origin'] && $rel[1]['origin'] && $rel[2]['origin'], 'three outlets with their own reporting');
        $this->assertNotSame($rel[0]['group'], $rel[1]['group']);
        $this->assertFalse($rel[3]['origin'], 'the aggregator repeating the LA Times is not independent');
        $this->assertSame($rel[2]['group'], $rel[3]['group']);
        $this->assertSame(3, SourceRelations::independentCount(SourcePacket::prepare($packets, 'Zelenskyy warning')));
        // A later copy of the same reporting can carry an UPDATED figure: it is told to treat that as an update, not as unverified.
        $render = SourcePacket::render(SourcePacket::prepare($packets, 'Zelenskyy warning'));
        $this->assertStringContainsString('LATER UPDATE of the same reporting', $render);
        $this->assertStringContainsString('3 hours after Los Angeles Times', $render);

        foreach (['https://www.president.gov.ua/en/news/x', 'https://www.state.gov/remarks/y', 'https://mod.gov.ua/z', 'https://www.mil.ru/q'] as $u) {
            $this->assertSame('primary', SourcePacket::kind(['url' => $u]), $u);
        }
        $q = WebResearcher::officialQueries('Zelenskyy said; Marco Rubio, Secretary of State; Ukrainian Air Force spokesperson Yurii Ihnat; the Russian Defense Ministry said');
        $joined = implode(' | ', $q);
        $this->assertStringContainsString('site:state.gov', $joined);
        $this->assertStringContainsString('site:president.gov.ua', $joined);
        $this->assertCount(3, $q);
        $this->assertSame(WebResearcher::officialQueries('Zelenskyy; Rubio; Ukrainian Air Force; Russian Defense Ministry')[0], $q[0]);
    }

    // 8 and 9: focus, relevance and repetition ─────────────────────────────────────────────────
    private function articleBody(): string
    {
        return '<p>Ukrainian President Volodymyr Zelenskyy said Monday that Russia launched overnight attacks on Ukraine’s energy infrastructure with ballistic missiles and jet-powered drones, and that Kyiv received no advance warning from partner countries before the first wave.</p>'
            . '<p>Zelenskyy wrote that once again there was no warning from partners, according to The Guardian. Ukrainian Air Force spokesperson Yurii Ihnat said Ukraine had not been warned before the first wave of strikes on power plants in Kyiv.</p>'
            . '<p>Zelenskyy later said the barrage struck 28 substations and other critical energy facilities, leaving more than half a million people without electricity, heat and water.</p>'
            . '<p>Secretary of State Marco Rubio said he had no information about the previous night’s attack and mentioned a software problem with the early warning system a few nights ago.</p>'
            . '<p>Ukraine has also complained about slowed deliveries of American weapons. On Sunday, Ukraine launched more than 1,000 drones at Russia in one of its largest aerial attacks of the war, setting fire to an oil export terminal and a depot in St. Petersburg.</p>';
    }

    public function test_an_ending_about_an_unrelated_development_is_flagged_and_a_relevant_ending_is_not(): void
    {
        $title = 'Zelenskyy says Ukraine got no warning before Russian strike';
        $this->assertNotNull(LeadCheck::unrelatedEnding($title, $this->articleBody()));
        $this->assertArrayHasKey('unrelated-ending', PublishGate::originality(new Post(['title' => $title, 'body' => $this->articleBody()])));

        $relevant = str_replace(
            substr($this->articleBody(), strrpos($this->articleBody(), '<p>')),
            '<p>Ihnat said information about the second wave reached Ukraine, but the Los Angeles Times reported it arrived too late for air raid sirens in Kyiv to warn residents of the strike on the power plants.</p>',
            $this->articleBody(),
        );
        $this->assertNull(LeadCheck::unrelatedEnding($title, $relevant), 'a related development that explains the warning dispute is fine');
    }

    public function test_headline_excerpt_and_introduction_repetition_is_detected_without_punishing_necessary_facts(): void
    {
        $excerpt = 'Volodymyr Zelenskyy said Ukraine received no advance warning before the first wave of an overnight Russian attack on energy infrastructure.';
        $this->assertTrue(LeadCheck::excerptRepeatsLead($excerpt, $this->articleBody()), 'the published excerpt repeats the lead');

        $better = 'Ukrainian officials say a second warning came too late for sirens; Moscow says it hit military targets.';
        $this->assertFalse(LeadCheck::excerptRepeatsLead($better, $this->articleBody()));

        $this->assertTrue(LeadCheck::excerptRestatesHeadline('Zelenskyy says Ukraine got no warning before Russian strike on power grid', 'Zelenskyy says Ukraine got no warning before Russian strike on power grid'));
        $this->assertFalse(LeadCheck::excerptRestatesHeadline('Officials reported a death in Kyiv and outages affecting more than half a million people.', 'Zelenskyy says Ukraine got no warning before Russian strike'));

        $twice = '<p>Zelenskyy said Ukraine received no advance warning before the first wave of the overnight attack on energy sites.</p><p>Later on, Zelenskyy said Ukraine got no advance warning before the first wave of the overnight attack on energy sites.</p>';
        $this->assertNotEmpty(LeadCheck::nearDuplicateSentences($twice));
        $this->assertEmpty(LeadCheck::nearDuplicateSentences($this->articleBody()));
    }

    // 8 (links) ─────────────────────────────────────────────────────────────────────────────────
    public function test_wikipedia_links_are_removed_from_news_but_source_links_stay(): void
    {
        $html = '<p>Secretary of State <a href="https://en.wikipedia.org/wiki/Marco_Rubio" target="_blank">Marco Rubio</a> told <a href="https://www.theguardian.com/world/x">The Guardian</a> he did not know.</p>';
        $this->assertTrue(LinkQuality::hasWikipedia($html));
        $this->assertArrayHasKey('wikipedia-link', PublishGate::originality(new Post(['title' => 'T', 'body' => $html])));

        $clean = LinkQuality::unwrapWikipedia($html);
        $this->assertStringNotContainsString('wikipedia.org', $clean);
        $this->assertStringContainsString('Marco Rubio told', strip_tags($clean));
        $this->assertStringContainsString('href="https://www.theguardian.com/world/x"', $clean);
    }

    private function evidenceText(): string
    {
        return 'Ukrainian President Volodymyr Zelenskyy said Russia launched overnight attacks on Ukraine’s energy infrastructure and that Kyiv received no advance warning from partner countries before the first wave. '
            . 'Ukrainian Air Force spokesperson Yurii Ihnat said Ukraine had not been warned before the first wave of strikes on power plants in Kyiv. Zelenskyy later said the barrage struck 28 substations and other critical energy facilities, '
            . 'leaving more than half a million people without electricity, heat and water. Secretary of State Marco Rubio said he had no information about the previous night’s attack and mentioned a software problem with the early warning system a few nights ago. '
            . 'Ukraine has also complained about slowed deliveries of American weapons. On Sunday, Ukraine launched more than 1,000 drones at Russia in one of its largest aerial attacks of the war, setting fire to an oil export terminal and a depot in St. Petersburg. '
            . str_repeat('Further reporting from the scene. ', 6);
    }

    // 10: the fact-checking stage ──────────────────────────────────────────────────────────────
    public function test_the_gpt_5_4_fact_check_receives_the_developing_news_rules_and_the_package(): void
    {
        $sent = ['system' => null, 'user' => null, 'model' => null];
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('json')->andReturnUsing(function ($stage, $system, $user, $props, $model = null) use (&$sent) {
            $sent = ['system' => $system, 'user' => $user, 'model' => $model];

            return ['claims_checked' => 5, 'issues' => [], 'source_conflicts' => [], 'unverified' => [], 'source_support' => [], 'sensitive' => false, 'summary' => 'ok'];
        });
        $pkg = $this->package() + ['event' => ['title' => 'Overnight strike', 'what_happened' => 'x', 'who' => [], 'where' => 'Kyiv', 'event_date' => '2026-10-18'], 'latest_development' => ['date' => '2026-10-19', 'summary' => 'second wave'], 'newsworthiness' => 'new_event'];
        $r = app(FactChecker::class)->check($ai, 'Title', $this->articleBody(), [['name' => 'The Guardian', 'url' => 'https://www.theguardian.com/x', 'text' => $this->evidenceText()]], null, false, $pkg);

        $this->assertSame('gpt-5.4', $sent['model']);
        foreach (['DEVELOPING EVENTS', 'WAVES AND STAGES', 'OFFICIAL CLAIMS', 'RELEVANCE', 'STATEMENT CHRONOLOGY', 'NUMBERS AND UNITS'] as $rule) {
            $this->assertStringContainsString($rule, (string) $sent['system']);
        }
        $this->assertStringContainsString('PHASES / WAVES', (string) $sent['user']);
        $this->assertStringContainsString('FIGURES (compare scope', (string) $sent['user']);
        $this->assertSame('ready', $r['verdict']);

        // A superseded figure stated as current is critical in the fact-check verdict (held, not published).
        $bad = '<p>Zelenskyy said the strikes damaged more than 10 energy infrastructure facilities.</p>';
        $held = app(FactChecker::class)->check($ai, 'Title', $bad, [['name' => 'The Guardian', 'url' => 'https://www.theguardian.com/x', 'text' => $this->evidenceText()]], null, false, $pkg);
        $this->assertNotSame('ready', $held['verdict']);
    }

    // Existing rules, models, disabled sections ─────────────────────────────────────────────────
    public function test_existing_rules_models_and_disabled_sections_are_unchanged(): void
    {
        $this->assertSame('gpt-5.4-mini', AiModels::writer());
        $this->assertSame('gpt-5.4', AiModels::factCheck());
        $this->assertSame('gpt-5.4', AiModels::research());
        $this->assertFalse(ArticleSections::takeawaysEnabled());
        $this->assertFalse(ArticleSections::ourTakeEnabled());
        $this->assertFalse(ArticleSections::sourcesEnabled());

        $policy = EditorialPolicy::text();
        foreach (['Developing news changes through the day', 'Two different figures are not a contradiction', 'comes in waves or stages', 'establishes what that party said', 'Before adding background', 'Do not link a person'] as $new) {
            $this->assertStringContainsString($new, $policy);
        }
        foreach (['Never combine similar people', 'Compare every quantity WITH its unit', 'Find and credit the ORIGINAL reporting', 'Keep each statement with the occasion and date'] as $kept) {
            $this->assertStringContainsString($kept, $policy, 'earlier rules are still there');
        }
    }
}
