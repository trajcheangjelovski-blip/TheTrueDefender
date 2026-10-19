<?php

namespace Tests\Feature\Concerns;

use App\Services\ResearchAnalyst;

/** A valid research package (what ResearchAnalyst returns) for tests that publish. */
trait ResearchFixtures
{
    protected function researchPackage(array $overrides = []): array
    {
        $today = now()->toDateString();
        $package = array_replace_recursive([
            'event' => [
                'title' => 'National Guard member shot near Farragut Square', 'what_happened' => 'A Guard member on patrol was shot.',
                'who' => ['D.C. National Guard member'], 'where' => 'Washington, D.C.', 'event_type' => 'shooting',
                'event_date' => $today, 'event_date_basis' => 'Packet 1: "on Monday"', 'ongoing' => false,
            ],
            'latest_development' => ['date' => $today, 'summary' => 'Police confirmed the shooting.'],
            'newsworthiness' => 'new_event',
            'breaking_eligible' => true,
            'packets' => [
                ['packet' => 1, 'same_event' => true, 'published' => $today, 'updated' => '', 'note' => ''],
                ['packet' => 2, 'same_event' => true, 'published' => $today, 'updated' => '', 'note' => ''],
            ],
            'confirmed_facts' => [['fact' => 'A Guard member was shot.', 'packets' => [1, 2]]],
            'quotes' => [], 'chronology' => [], 'background' => [], 'conflicts' => [], 'unverified' => [],
            'missing_evidence' => [], 'angle' => 'Police confirmed the shooting.', 'reader_questions' => [],
            'needs_more_research' => false,
            'model' => 'gpt-5.4', 'researched_at' => now()->toIso8601String(), 'packet_urls' => ['https://a.test/1', 'https://b.test/2'],
        ], $overrides);
        $package['event_key'] = ResearchAnalyst::eventKey($package['event']);

        return $package;
    }
}
