<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IngestedItemResource\Pages;
use App\Models\IngestedItem;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IngestedItemResource extends Resource
{
    protected static ?string $model = IngestedItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Ingest Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->limit(60)->searchable()->weight('bold')
                    ->description(fn (IngestedItem $r) => $r->source?->name),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'duplicate' => 'info',
                        default => 'gray',
                    })
                    ->tooltip(fn (IngestedItem $r) => $r->status === 'duplicate' ? $r->error : null),
                Tables\Columns\TextColumn::make('post.status')->label('Post')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('source_url')->label('Source')->url(fn (IngestedItem $r) => $r->source_url, true)
                    ->formatStateUsing(fn () => 'link ↗')->color('info'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['processed' => 'Processed', 'pending' => 'Pending', 'failed' => 'Failed', 'duplicate' => 'Duplicate']),
                Tables\Filters\SelectFilter::make('ingest_source_id')
                    ->relationship('source', 'name')->label('Feed'),
            ])
            ->actions([
                Tables\Actions\Action::make('editPost')
                    ->label('Open post')
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (IngestedItem $r) => $r->post_id !== null)
                    ->url(fn (IngestedItem $r) => $r->post ? PostResource::getUrl('edit', ['record' => $r->post]) : null),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIngestedItems::route('/'),
        ];
    }
}
