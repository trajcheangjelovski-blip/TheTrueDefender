<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageSeoResource\Pages;
use App\Models\PageSeo;
use App\Services\SeoAnalyzer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageSeoResource extends Resource
{
    protected static ?string $model = PageSeo::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Page SEO';

    protected static ?string $modelLabel = 'page';

    protected static ?string $pluralModelLabel = 'Page SEO';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_posts') ?? false;
    }

    /** Analyze a static page by fetching its rendered HTML, then scoring it. */
    public static function analyzePage(PageSeo $record, array $formState = []): array
    {
        // Page not reachable (server down / not deployed) → body null, meta-only score.
        $body = app(\App\Services\SeoOptimizer::class)->renderedBody(url($record->path));

        return app(SeoAnalyzer::class)->analyze(
            (string) ($formState['meta_title'] ?? $record->meta_title ?: $record->label),
            $body,
            $formState['meta_title'] ?? $record->meta_title,
            $formState['meta_description'] ?? $record->meta_description,
            $formState['focus_keyword'] ?? $record->focus_keyword,
            url($record->path),
        );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(fn (PageSeo $record) => $record->label . ' — SEO')
                ->description(fn (PageSeo $record) => 'URL: ' . url($record->path))
                ->schema([
                    Forms\Components\Placeholder::make('seo_result')
                        ->hiddenLabel()
                        ->content(fn (Get $get) => PostResource::renderScore($get('seo_score'), $get('seo_analysis')))
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('analyze_seo')
                            ->label('Optimize with AI')
                            ->icon('heroicon-o-sparkles')
                            ->color('primary')
                            ->action(function (Get $get, Set $set, PageSeo $record) {
                                $analysis = self::analyzePage($record, [
                                    'meta_title' => $get('meta_title'),
                                    'meta_description' => $get('meta_description'),
                                    'focus_keyword' => $get('focus_keyword'),
                                ]);
                                $set('seo_score', $analysis['score']);
                                $set('seo_analysis', $analysis);
                                $set('seo_analyzed_at', now());

                                // Apply the AI-optimized meta (editable before saving).
                                foreach (['meta_title', 'meta_description', 'focus_keyword'] as $field) {
                                    if (filled($analysis['suggested'][$field] ?? null)) {
                                        $set($field, $analysis['suggested'][$field]);
                                    }
                                }

                                Notification::make()
                                    ->title("SEO score: {$analysis['score']}/100 ({$analysis['grade']})")
                                    ->body($analysis['summary'] . ' Meta fields updated with AI suggestions — adjust if needed, then save.')
                                    ->color($analysis['score'] >= 80 ? 'success' : ($analysis['score'] >= 50 ? 'warning' : 'danger'))
                                    ->send();
                            }),
                    ]),

                    Forms\Components\TextInput::make('meta_title')
                        ->label('Meta title')->maxLength(70)
                        ->helperText('Aim 30–60 chars.')->columnSpanFull(),
                    Forms\Components\Textarea::make('meta_description')
                        ->label('Meta description')->rows(2)->maxLength(180)
                        ->helperText('Aim 120–160 chars.')->columnSpanFull(),
                    Forms\Components\TextInput::make('focus_keyword')->label('Focus keyword'),

                    Forms\Components\Hidden::make('seo_score'),
                    Forms\Components\Hidden::make('seo_analysis'),
                    Forms\Components\Hidden::make('seo_analyzed_at'),
                ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Page')->weight('bold')
                    ->description(fn (PageSeo $r) => $r->path),
                Tables\Columns\TextColumn::make('seo_score')
                    ->label('SEO')->badge()->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state . '/100')
                    ->color(fn ($state) => $state === null ? 'gray'
                        : ($state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')))
                    ->tooltip(fn (PageSeo $r) => $r->seo_analysis['summary'] ?? null),
                Tables\Columns\TextColumn::make('gsc_position')
                    ->label('Google #')->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : '#' . number_format($state, 1)),
                Tables\Columns\TextColumn::make('gsc_clicks')->label('Clicks')->numeric()->placeholder('—'),
                Tables\Columns\TextColumn::make('meta_title')->label('Meta title')->limit(40)->placeholder('— (uses default)'),
                Tables\Columns\TextColumn::make('seo_analyzed_at')->label('Analyzed')->since()->placeholder('never'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPageSeos::route('/'),
            'edit' => Pages\EditPageSeo::route('/{record}/edit'),
        ];
    }
}
