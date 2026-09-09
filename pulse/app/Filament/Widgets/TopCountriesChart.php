<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardDates;
use App\Models\ClickEvent;
use App\Models\Visit;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

class TopCountriesChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardDates;

    protected static ?string $heading = 'Visits by country (top 12)';

    protected static ?int $sort = 6;

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        // Visits carry a rolling window (rows are pruned after ~7 days), so scope
        // by last_seen_at within the selected range.
        $rows = Visit::whereBetween('last_seen_at', [$this->rangeStart(), $this->rangeEnd()])
            ->whereNotNull('country')
            ->select('country', DB::raw('count(*) as total'))
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit(12)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Visitors',
                    'data' => $rows->pluck('total')->all(),
                    'backgroundColor' => '#f59e0b',
                    'borderColor' => '#d97706',
                ],
            ],
            'labels' => $rows->map(fn ($r) => ClickEvent::flag($r->country) . ' ' . $r->country)->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
