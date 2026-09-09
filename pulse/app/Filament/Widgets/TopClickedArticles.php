<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardDates;
use App\Models\ClickEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class TopClickedArticles extends BaseWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardDates;

    protected static ?int $sort = 7;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Top clicked articles';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ClickEvent::query()
                    ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
                    // MIN(id) gives each grouped row a unique key for the table.
                    ->select('post_id', DB::raw('MIN(id) as id'), DB::raw('COUNT(*) as clicks'), DB::raw('COUNT(DISTINCT country) as country_count'))
                    ->whereNotNull('post_id')
                    ->groupBy('post_id')
                    ->with('post:id,title,slug')
            )
            ->columns([
                TextColumn::make('post.title')
                    ->label('Article')
                    ->wrap()
                    ->limit(90)
                    ->url(fn (ClickEvent $record) => $record->post
                        ? route('post.show', $record->post)
                        : null, shouldOpenInNewTab: true)
                    ->default('(deleted post)'),

                TextColumn::make('clicks')
                    ->label('Clicks')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('country_count')
                    ->label('Countries')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('top_country')
                    ->label('Top country')
                    ->state(fn (ClickEvent $record) => $this->topCountry((int) $record->post_id)),
            ])
            ->defaultSort('clicks', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->poll('60s');
    }

    /** The single most-common country for a post's clicks in the current range. */
    protected function topCountry(int $postId): string
    {
        $row = ClickEvent::query()
            ->where('post_id', $postId)
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->whereNotNull('country')
            ->select('country', DB::raw('COUNT(*) as c'))
            ->groupBy('country')
            ->orderByDesc('c')
            ->first();

        return $row
            ? ClickEvent::flag($row->country) . ' ' . $row->country . ' (' . $row->c . ')'
            : '—';
    }
}
