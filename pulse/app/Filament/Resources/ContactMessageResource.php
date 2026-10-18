<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Read and triage messages sent through the public contact form. */
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Audience';

    protected static ?string $navigationLabel = 'Contact messages';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::whereNull('handled_at')->count() ?: null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, g:i A')->sortable()->label('Received'),
                Tables\Columns\TextColumn::make('topic')->badge()
                    ->formatStateUsing(fn (string $state) => ContactMessage::TOPICS[$state] ?? $state),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(50),
                Tables\Columns\IconColumn::make('handled_at')->label('Handled')->boolean()
                    ->getStateUsing(fn (ContactMessage $r) => $r->handled_at !== null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('topic')->options(ContactMessage::TOPICS),
                Tables\Filters\TernaryFilter::make('handled')->queries(
                    true: fn ($q) => $q->whereNotNull('handled_at'),
                    false: fn ($q) => $q->whereNull('handled_at'),
                    blank: fn ($q) => $q,
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->form([
                    Forms\Components\TextInput::make('name')->disabled(),
                    Forms\Components\TextInput::make('email')->disabled(),
                    Forms\Components\TextInput::make('subject')->disabled(),
                    Forms\Components\Textarea::make('message')->rows(10)->disabled(),
                ]),
                Tables\Actions\Action::make('handled')->label('Mark handled')->icon('heroicon-o-check')
                    ->visible(fn (ContactMessage $r) => $r->handled_at === null)
                    ->action(fn (ContactMessage $r) => $r->update(['handled_at' => now()])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListContactMessages::route('/')];
    }
}
