<?php

namespace App\Filament\Resources;

use App\Models\NewsroomOutbox;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NewsroomOutboxResource extends Resource
{
    protected static ?string $model = NewsroomOutbox::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Truth Social queue';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage_settings') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('post_id')->label('Article')->searchable(),
            Tables\Columns\TextColumn::make('payload.title')->label('Title')->limit(55),
            Tables\Columns\TextColumn::make('profile')->searchable(),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('attempts'),
            Tables\Columns\TextColumn::make('external_url')->label('Published link')->wrap(),
            Tables\Columns\TextColumn::make('error')->wrap()->limit(100),
            Tables\Columns\TextColumn::make('updated_at')->dateTime(),
        ])->defaultSort('id', 'desc')->actions([
            Tables\Actions\Action::make('retry')->label('Retry delivery')->visible(fn ($record) => $record->status === 'delivery_failed')
                ->action(fn ($record) => $record->update(['status' => 'pending', 'next_attempt_at' => null])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => NewsroomOutboxResource\Pages\ListNewsroomOutbox::route('/')];
    }
}
