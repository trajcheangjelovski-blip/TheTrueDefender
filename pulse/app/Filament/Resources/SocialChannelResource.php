<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialChannelResource\Pages;
use App\Models\SocialChannel;
use App\Services\Social\SocialManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SocialChannelResource extends Resource
{
    protected static ?string $model = SocialChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Social Channels';

    public static function form(Form $form): Form
    {
        $manager = app(SocialManager::class);

        // One config section per driver, shown only when that driver is selected.
        $configSections = [];
        foreach ($manager->all() as $key => $driver) {
            $fields = [];
            foreach ($driver->configFields() as $fieldKey => $label) {
                $fields[] = Forms\Components\TextInput::make("config.{$fieldKey}")
                    ->label($label)
                    ->password()->revealable()
                    ->autocomplete(false);
            }
            $configSections[] = Forms\Components\Section::make($driver->label() . ' credentials')
                ->schema($fields)
                ->columns(2)
                ->visible(fn (Forms\Get $get) => $get('driver') === $key);
        }

        return $form->schema(array_merge([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('driver')
                    ->options($manager->options())
                    ->required()->native(false)->live(),
                Forms\Components\TextInput::make('name')->required()
                    ->helperText('A label for you, e.g. "Main Telegram channel".'),
                Forms\Components\Toggle::make('is_active')->default(true)
                    ->helperText('Auto-post published stories to this channel.'),
            ]),
        ], $configSections));
    }

    public static function table(Table $table): Table
    {
        $labels = app(SocialManager::class)->options();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $labels[$state] ?? $state),
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y')->sortable(),
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
            'index' => Pages\ListSocialChannels::route('/'),
            'create' => Pages\CreateSocialChannel::route('/create'),
            'edit' => Pages\EditSocialChannel::route('/{record}/edit'),
        ];
    }
}
