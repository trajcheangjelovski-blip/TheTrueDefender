<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IngestSourceResource\Pages;
use App\Models\IngestSource;
use App\Services\IngestService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IngestSourceResource extends Resource
{
    protected static ?string $model = IngestSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-rss';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'News Feeds';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->columnSpanFull(),
                Forms\Components\TextInput::make('feed_url')
                    ->label('RSS / Atom feed URL')
                    ->url()->required()->columnSpanFull()
                    ->helperText('e.g. https://feeds.bbci.co.uk/news/world/rss.xml'),

                Forms\Components\Select::make('category_id')
                    ->relationship('category', 'name')->searchable()->preload()
                    ->required()->native(false)
                    ->helperText('Ingested posts are filed under this category.'),
                Forms\Components\Select::make('author_id')
                    ->relationship('author', 'name')->searchable()->preload()
                    ->native(false)->default(auth()->id())
                    ->helperText('Bylined author for ingested posts.'),

                Forms\Components\TextInput::make('max_items')
                    ->numeric()->default(5)->minValue(1)->maxValue(25)
                    ->helperText('How many of the newest items to pull per fetch.'),
            ]),

            Forms\Components\Section::make('Automation & legal')->columns(2)->schema([
                Forms\Components\Toggle::make('is_active')->default(true)
                    ->helperText('Include this feed in scheduled runs.'),
                Forms\Components\Toggle::make('auto_publish')
                    ->label('Auto-publish')
                    ->helperText('ON: publish instantly (fires push + social). OFF: save as draft for review.'),
                Forms\Components\Toggle::make('fetch_images')
                    ->label('Add images')
                    ->live()
                    ->helperText('ON: give each post an image (see mode below). OFF = category art only.'),
                Forms\Components\Toggle::make('ai_image')
                    ->label('Generate original image with AI')
                    ->helperText('ON: OpenAI creates an original image (safer). OFF: copy the source photo (⚠️ copyright risk).')
                    ->visible(fn (Forms\Get $get) => (bool) $get('fetch_images')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('category.name')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
                Tables\Columns\IconColumn::make('auto_publish')->boolean()->label('Auto'),
                Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Ingested'),
                Tables\Columns\TextColumn::make('last_fetched_at')->since()->placeholder('never')->label('Last run'),
            ])
            ->actions([
                Tables\Actions\Action::make('runNow')
                    ->label('Run now')
                    ->icon('heroicon-m-arrow-path')
                    ->action(function (IngestSource $record) {
                        $count = app(IngestService::class)->runSource($record);
                        Notification::make()
                            ->title("Ingest finished — {$count} new post(s)")
                            ->success()->send();
                    }),
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
            'index' => Pages\ListIngestSources::route('/'),
            'create' => Pages\CreateIngestSource::route('/create'),
            'edit' => Pages\EditIngestSource::route('/{record}/edit'),
        ];
    }
}
