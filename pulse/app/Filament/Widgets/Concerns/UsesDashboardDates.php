<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Carbon;

/**
 * Reads the dashboard's From/To date filters (App\Filament\Pages\Dashboard).
 * Falls back to the last 30 days when the filters aren't set.
 */
trait UsesDashboardDates
{
    protected function rangeStart(): Carbon
    {
        $start = $this->filters['startDate'] ?? null;

        return $start ? Carbon::parse($start)->startOfDay() : now()->subDays(29)->startOfDay();
    }

    protected function rangeEnd(): Carbon
    {
        $end = $this->filters['endDate'] ?? null;
        $end = $end ? Carbon::parse($end)->endOfDay() : now()->endOfDay();

        // Never let the end run past "now" (dates are inclusive).
        return $end->greaterThan(now()) ? now() : $end;
    }

    /** Inclusive number of days in the range, guarded for looping. */
    protected function rangeDays(): int
    {
        $days = (int) $this->rangeStart()->diffInDays($this->rangeEnd()) + 1;

        return max(1, min($days, 366));
    }
}
