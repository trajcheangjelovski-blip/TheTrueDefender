<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriberResource\Pages;
use App\Models\Subscriber;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', 'subscribed')->count() ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->email()->required(),
            Forms\Components\TextInput::make('name'),
            Forms\Components\Select::make('status')
                ->options(['subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed'])
                ->default('subscribed')->native(false)->required(),
            Forms\Components\TextInput::make('source')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('name')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'subscribed' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('source')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y')->sortable()->label('Joined'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed']),
                Tables\Filters\SelectFilter::make('source')
                    ->options(['footer' => 'Footer', 'newsletter' => 'Newsletter', 'popup' => 'Popup', 'inline' => 'Inline']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
