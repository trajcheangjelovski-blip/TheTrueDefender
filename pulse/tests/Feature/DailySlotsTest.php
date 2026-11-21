<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\DailyCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_limits_never_block_publication(): void
    {
        Setting::put('daily_publish_cap', '1');
        Setting::put('publish_score_threshold', '100');
        Setting::put('min_publish_gap_minutes', '1440');
        Setting::put('category_quotas', json_encode(['politics' => 0]));
        $this->assertSame(0, DailyCap::cap());
        $this->assertSame(0, DailyCap::scoreBar('politics'));
        $this->assertSame(PHP_INT_MAX, DailyCap::remainingFor('politics'));
        $this->assertTrue(DailyCap::paceAllows());
        $this->assertFalse(DailyCap::reached());
    }

    public function test_no_reserved_slots_or_category_competition_remain(): void
    {
        $this->assertSame([], DailyCap::sectionSlots());
        $this->assertSame([], DailyCap::unfilledSlots());
        $this->assertSame(0, DailyCap::reserve());
        $this->assertSame(0, DailyCap::gapMinutes());
    }
}
