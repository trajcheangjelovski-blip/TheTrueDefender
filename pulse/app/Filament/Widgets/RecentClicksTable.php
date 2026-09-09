<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardDates;
use App\Models\ClickEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentClicksTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardDates;

    protected static ?int $sort = 8;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Recent article clicks';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ClickEvent::query()
                    ->with('post:id,title,slug')
                    ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->tooltip(fn (ClickEvent $record) => $record->created_at?->format('M j, Y H:i'))
                    ->sortable(),

                TextColumn::make('country')
                    ->label('Country')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? ClickEvent::flag($state) . ' ' . $state
                        : '🏳️ —')
                    ->badge(),

                TextColumn::make('post.title')
                    ->label('Article clicked')
                    ->wrap()
                    ->limit(80)
                    ->url(fn (ClickEvent $record) => $record->post
                        ? route('post.show', $record->post)
                        : null, shouldOpenInNewTab: true)
                    ->default('(deleted post)'),
            ])
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->poll('30s');
    }
}
