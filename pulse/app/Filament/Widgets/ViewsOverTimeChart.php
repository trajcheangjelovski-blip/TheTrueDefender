<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardDates;
use App\Models\StatDaily;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class ViewsOverTimeChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardDates;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public function getHeading(): string
    {
        $total = (int) StatDaily::whereBetween('stat_date', [
            $this->rangeStart()->toDateString(),
            $this->rangeEnd()->toDateString(),
        ])->sum('views');

        return 'Views — ' . number_format($total) . ' from '
            . $this->rangeStart()->format('M j, Y') . ' to ' . $this->rangeEnd()->format('M j, Y');
    }

    protected function getData(): array
    {
        $start = $this->rangeStart();
        $days = $this->rangeDays();

        $byDay = StatDaily::whereBetween('stat_date', [
            $start->toDateString(),
            $this->rangeEnd()->toDateString(),
        ])->pluck('views', 'stat_date');

        $lookup = [];
        foreach ($byDay as $date => $views) {
            $lookup[Carbon::parse($date)->toDateString()] = (int) $views;
        }

        $labels = [];
        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $labels[] = $date->format('M j');
            $data[] = $lookup[$date->toDateString()] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Views',
                    'data' => $data,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
