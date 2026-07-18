<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdPlacementResource\Pages;
use App\Models\AdPlacement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AdPlacementResource extends Resource
{
    protected static ?string $model = AdPlacement::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Ads';

    protected static ?string $navigationLabel = 'Ad Placements';

    public static function canCreate(): bool
    {
        return false; // fixed set of placement slots
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_ads') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Placeholder::make('name')
                    ->content(fn (AdPlacement $r) => $r->name),
                Forms\Components\Placeholder::make('where')
                    ->label('Where it shows')
                    ->content(fn (AdPlacement $r) => $r->description),

                Forms\Components\Toggle::make('is_enabled')
                    ->label('Show this ad')
                    ->helperText('Off = this slot is hidden everywhere it appears.'),

                Forms\Components\Select::make('format')
                    ->options(['display' => 'Display (responsive banner)', 'in-article' => 'In-article (native)'])
                    ->native(false)->required(),

                Forms\Components\TextInput::make('ad_slot')
                    ->label('AdSense slot ID')
                    ->helperText('The data-ad-slot number for this placement (uses the global publisher ID from AI/Ads settings).'),

                Forms\Components\Textarea::make('custom_html')
                    ->rows(4)
                    ->label('Custom ad code (optional)')
                    ->helperText('Paste any ad network code here to override AdSense for this placement.'),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold'),
                Tables\Columns\TextColumn::make('description')->color('gray')->wrap(),
                Tables\Columns\TextColumn::make('format')->badge(),
                Tables\Columns\IconColumn::make('is_enabled')->boolean()->label('Shown'),
                Tables\Columns\IconColumn::make('ad_slot')->label('Configured')
                    ->boolean()
                    ->state(fn (AdPlacement $r) => filled($r->ad_slot) || filled($r->custom_html)),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle')
                    ->label(fn (AdPlacement $r) => $r->is_enabled ? 'Hide' : 'Show')
                    ->icon(fn (AdPlacement $r) => $r->is_enabled ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
                    ->action(fn (AdPlacement $r) => $r->update(['is_enabled' => ! $r->is_enabled])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdPlacements::route('/'),
            'edit' => Pages\EditAdPlacement::route('/{record}/edit'),
        ];
    }
}
