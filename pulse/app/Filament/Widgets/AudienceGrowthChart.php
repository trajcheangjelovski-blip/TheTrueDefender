<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardDates;
use App\Models\PushSubscription;
use App\Models\Subscriber;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class AudienceGrowthChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardDates;

    protected static ?string $heading = 'Audience growth';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $start = $this->rangeStart();
        $end = $this->rangeEnd();
        $days = $this->rangeDays();

        // New rows per day, keyed by Y-m-d.
        $subsByDay = Subscriber::whereBetween('created_at', [$start, $end])
            ->get(['created_at'])
            ->groupBy(fn ($row) => $row->created_at->format('Y-m-d'))
            ->map->count();

        $pushByDay = PushSubscription::whereBetween('created_at', [$start, $end])
            ->get(['created_at'])
            ->groupBy(fn ($row) => $row->created_at->format('Y-m-d'))
            ->map->count();

        $labels = [];
        $subsData = [];
        $pushData = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->format('Y-m-d');

            $labels[] = $date->format('M j');
            $subsData[] = $subsByDay[$key] ?? 0;
            $pushData[] = $pushByDay[$key] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Newsletter subscribers',
                    'data' => $subsData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Push devices',
                    'data' => $pushData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
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
