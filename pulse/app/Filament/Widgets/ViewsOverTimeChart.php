<?php

namespace App\Filament\Widgets;

use App\Models\Post;
use App\Models\StatDaily;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ViewsOverTimeChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    /** Default selected period. */
    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            'today' => 'Today',
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            'all' => 'All time',
        ];
    }

    public function getHeading(): string
    {
        $total = $this->totalViews();

        return 'Views — ' . number_format($total) . ' ' . $this->periodLabel();
    }

    /** True view total for the selected period. */
    protected function totalViews(): int
    {
        // All time uses the real lifetime counter (daily buckets only start now).
        if ($this->filter === 'all') {
            return (int) Post::sum('views');
        }

        return (int) StatDaily::where('stat_date', '>=', $this->startDate()->toDateString())
            ->sum('views');
    }

    protected function periodLabel(): string
    {
        return match ($this->filter) {
            'today' => 'today',
            '7' => 'in the last 7 days',
            '90' => 'in the last 90 days',
            'all' => 'all time',
            default => 'in the last 30 days',
        };
    }

    protected function startDate(): Carbon
    {
        return match ($this->filter) {
            'today' => now()->startOfDay(),
            '7' => now()->subDays(6)->startOfDay(),
            '90' => now()->subDays(89)->startOfDay(),
            'all' => optional(StatDaily::min('stat_date'))
                ? Carbon::parse(StatDaily::min('stat_date'))
                : now()->subDays(29)->startOfDay(),
            default => now()->subDays(29)->startOfDay(),
        };
    }

    protected function getData(): array
    {
        $start = $this->startDate()->startOfDay();
        $end = now()->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $days = max(1, min($days, 366)); // guard the loop

        $byDay = StatDaily::where('stat_date', '>=', $start->toDateString())
            ->pluck('views', 'stat_date');

        // Keys come back as Y-m-d strings; normalise so lookups are reliable.
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
