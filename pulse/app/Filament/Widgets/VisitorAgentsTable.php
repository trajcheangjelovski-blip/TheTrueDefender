<?php

namespace App\Filament\Widgets;

use App\Models\Visit;
use App\Support\BotDetector;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Shows the distinct User-Agents seen in the last 24h with a hit count, so the
 * admin can spot bots that slip past the write-time filter by spoofing a real
 * browser UA. Flagged rows are ones BotDetector already recognises; unflagged
 * rows with datacenter-looking or oddly-old UAs are the spoofers to watch.
 */
class VisitorAgentsTable extends BaseWidget
{
    protected static ?int $sort = 9;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Visitor agents (last 24h)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Visit::query()
                    ->where('last_seen_at', '>=', now()->subDay())
                    ->whereNotNull('user_agent')
                    ->selectRaw('MIN(id) as id, user_agent, COUNT(*) as hits, MAX(last_seen_at) as seen_at')
                    ->groupBy('user_agent')
                    ->orderByDesc('hits')
            )
            ->columns([
                TextColumn::make('hits')
                    ->label('Visits')
                    ->badge()
                    ->sortable(),

                TextColumn::make('bot')
                    ->label('Flagged')
                    ->state(fn (Visit $record) => BotDetector::isBotUserAgent((string) $record->user_agent) ? 'bot' : 'ok')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'bot' ? '🤖 bot' : '✅ ok')
                    ->color(fn (string $state) => $state === 'bot' ? 'danger' : 'success'),

                TextColumn::make('seen_at')
                    ->label('Last seen')
                    ->since()
                    ->sortable(),

                TextColumn::make('user_agent')
                    ->label('User-Agent')
                    ->wrap()
                    ->limit(140)
                    ->tooltip(fn (Visit $record) => $record->user_agent),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100])
            ->poll('30s');
    }
}
