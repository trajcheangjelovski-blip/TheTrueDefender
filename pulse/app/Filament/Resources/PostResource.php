<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
use App\Services\SeoAnalyzer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)->schema([

                // ── Main column ──
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make()->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) =>
                                $operation === 'create' ? $set('slug', Str::slug($state)) : null)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->helperText('URL: /post/your-slug')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('excerpt')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Short summary shown on cards and in social shares.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('social_text')
                            ->label('Social caption')
                            ->rows(2)
                            ->maxLength(400)
                            ->helperText('The short hook auto-posted to social channels (Truth Social, etc.) before the link. AI writes this automatically; edit if you like. Blank = generated on first post.')
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('body')
                            ->columnSpanFull(),
                    ]),

                    self::seoSection(),
                ])->columnSpan(2),

                // ── Sidebar column ──
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Publish')->schema([
                        Forms\Components\Select::make('status')
                            ->options(['draft' => 'Draft', 'published' => 'Published'])
                            ->default('draft')
                            ->required()
                            ->native(false),

                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Publish date')
                            ->helperText('Leave empty to use now when publishing.'),

                        Forms\Components\Toggle::make('is_featured')
                            ->label('Top story (hero slider)')
                            ->helperText('Feature this at the top of the homepage.'),

                        Forms\Components\Toggle::make('allow_comments')
                            ->label('Allow reader comments')
                            ->helperText('Readers can post opinions (held for your approval). Defaults on for Opinion posts.')
                            ->default(fn (Forms\Get $get) => optional(\App\Models\Category::find($get('category_id')))->slug === 'opinion'),

                        Forms\Components\Toggle::make('is_breaking')
                            ->label('Breaking news')
                            ->helperText('Shows in the red BREAKING ticker.')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                // Default a 12h expiry when switched on and none set.
                                if ($state && blank($get('breaking_until'))) {
                                    $set('breaking_until', now()->addHours(12));
                                }
                            }),

                        Forms\Components\DateTimePicker::make('breaking_until')
                            ->label('Breaking until')
                            ->helperText('Auto-drops from the ticker at this time. Blank = until you turn it off.')
                            ->visible(fn (Forms\Get $get) => (bool) $get('is_breaking')),

                        Forms\Components\Toggle::make('is_trending')
                            ->label('Trending now')
                            ->helperText('Pin to the homepage Trending strip.')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                if ($state && blank($get('trending_until'))) {
                                    $set('trending_until', now()->addHours(48));
                                }
                            }),

                        Forms\Components\DateTimePicker::make('trending_until')
                            ->label('Trending until')
                            ->helperText('Auto-unpins at this time. Blank = until you turn it off.')
                            ->visible(fn (Forms\Get $get) => (bool) $get('is_trending')),
                    ]),

                    Forms\Components\Section::make('Organize')->schema([
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('author_id')
                            ->relationship('author', 'name')
                            ->searchable()
                            ->preload()
                            ->default(auth()->id())
                            ->native(false),
                    ]),

                    Forms\Components\Section::make('Image')->schema([
                        Forms\Components\FileUpload::make('featured_image')
                            ->image()
                            ->imageEditor()
                            ->directory('posts')
                            ->helperText('Optional. If empty, the emoji below is shown.'),

                        Forms\Components\TextInput::make('image_icon')
                            ->label('Emoji fallback')
                            ->maxLength(16)
                            ->placeholder('🏛️'),
                    ]),

                    Forms\Components\Section::make('Source attribution')
                        ->description('Filled automatically for AI-ingested posts.')
                        ->collapsed()
                        ->schema([
                            Forms\Components\TextInput::make('source_name'),
                            Forms\Components\TextInput::make('source_url')->url(),
                        ]),
                ])->columnSpan(1),
            ]),
        ]);
    }

    /** The SEO editor panel: meta fields, AI "Analyze" action, live score + checklist. */
    public static function seoSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('SEO')
            ->icon('heroicon-o-magnifying-glass-circle')
            ->description('On-page optimization. Run the AI analysis to get a score and fixes.')
            ->collapsible()
            ->schema([
                Forms\Components\Placeholder::make('seo_result')
                    ->hiddenLabel()
                    ->content(fn (Get $get) => self::renderScore($get('seo_score'), $get('seo_analysis')))
                    ->columnSpanFull(),

                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('analyze_seo')
                        ->label('Optimize with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->action(function (Get $get, Set $set) {
                            $analysis = app(SeoAnalyzer::class)->analyze(
                                (string) $get('title'),
                                $get('body'),
                                $get('meta_title'),
                                $get('meta_description'),
                                $get('focus_keyword'),
                                filled($get('slug')) ? url('/post/' . $get('slug')) : null,
                            );

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
                                ->body($analysis['summary'] . ' Meta fields updated with AI suggestions — adjust them if needed, then save.')
                                ->color($analysis['score'] >= 80 ? 'success' : ($analysis['score'] >= 50 ? 'warning' : 'danger'))
                                ->send();
                        }),
                ]),

                Forms\Components\TextInput::make('meta_title')
                    ->label('Meta title')
                    ->maxLength(70)
                    ->helperText('Shown in search results & browser tab. Aim 30–60 chars. Blank = post title.')
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('meta_description')
                    ->label('Meta description')
                    ->rows(2)
                    ->maxLength(180)
                    ->helperText('Search-result snippet. Aim 120–160 chars. Blank = excerpt.')
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('focus_keyword')
                    ->label('Focus keyword')
                    ->helperText('The main phrase you want this post to rank for.'),

                // Persisted by the form; not directly edited.
                Forms\Components\Hidden::make('seo_score'),
                Forms\Components\Hidden::make('seo_analysis'),
                Forms\Components\Hidden::make('seo_analyzed_at'),
            ])
            ->columns(1);
    }

    /** Build the score gauge + checklist HTML from stored analysis. */
    public static function renderScore($score, $analysis): HtmlString
    {
        if ($score === null || blank($analysis)) {
            return new HtmlString(
                '<div style="color:#9ca3af;font-size:.9rem">Not analyzed yet — click <strong>Analyze with AI</strong>.</div>'
            );
        }

        $analysis = is_array($analysis) ? $analysis : json_decode($analysis, true);
        $score = (int) $score;
        $color = $score >= 80 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626');
        $label = $score >= 80 ? 'Good' : ($score >= 50 ? 'Needs work' : 'Poor');

        $rows = '';
        foreach ($analysis['checks'] ?? [] as $c) {
            $dot = ['pass' => '#16a34a', 'warn' => '#d97706', 'fail' => '#dc2626'][$c['status']] ?? '#9ca3af';
            $rows .= '<li style="display:flex;gap:.5rem;align-items:flex-start;margin:.15rem 0">'
                . '<span style="color:' . $dot . ';font-weight:700">●</span>'
                . '<span><strong>' . e($c['label']) . '</strong> — ' . e($c['message']) . '</span></li>';
        }

        $fixes = '';
        foreach (array_slice($analysis['suggestions'] ?? [], 0, 5) as $s) {
            $fixes .= '<li style="margin:.15rem 0">' . e($s) . '</li>';
        }
        $fixesBlock = $fixes === '' ? '' :
            '<div style="margin-top:.6rem"><strong>Top fixes</strong><ol style="margin:.25rem 0 0 1.1rem;padding:0">' . $fixes . '</ol></div>';

        $engine = ($analysis['engine'] ?? 'local') === 'openai' ? 'AI analysis' : 'Local heuristic';

        return new HtmlString(
            '<div style="display:flex;gap:1rem;align-items:center;margin-bottom:.5rem">'
            . '<div style="flex:0 0 auto;width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;'
            . 'font-size:1.3rem;font-weight:800;color:#fff;background:' . $color . '">' . $score . '</div>'
            . '<div><div style="font-weight:700;color:' . $color . '">' . $label . ' · ' . $score . '/100</div>'
            . '<div style="font-size:.85rem;color:#9ca3af">' . e($analysis['summary'] ?? '') . ' <em>(' . $engine . ')</em></div></div>'
            . '</div>'
            . '<ul style="list-style:none;margin:0;padding:0;font-size:.85rem">' . $rows . '</ul>'
            . $fixesBlock
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('image_icon')->label('')->size('lg'),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(50)
                    ->weight('bold')
                    ->description(fn (Post $r) => Str::limit($r->excerpt, 60)),
                Tables\Columns\TextColumn::make('category.name')
                    ->badge()
                    ->color(fn (Post $r) => \Filament\Support\Colors\Color::hex($r->category?->color ?? '#e33b4e'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('seo_score')
                    ->label('SEO')
                    ->badge()
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state . '/100')
                    ->color(fn ($state) => $state === null ? 'gray'
                        : ($state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')))
                    ->tooltip(fn (Post $r) => $r->seo_analysis['summary'] ?? null),
                Tables\Columns\TextColumn::make('gsc_position')
                    ->label('Google #')
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : '#' . number_format($state, 1))
                    ->tooltip('Average Google position (Search Console). Lower is better.'),
                Tables\Columns\TextColumn::make('gsc_clicks')
                    ->label('Clicks')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('gsc_impressions')
                    ->label('Impr.')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Top')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_breaking')
                    ->label('Breaking')
                    ->boolean()
                    ->trueIcon('heroicon-s-bolt')
                    ->trueColor('danger')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_trending')
                    ->label('Trending')
                    ->boolean()
                    ->trueIcon('heroicon-s-fire')
                    ->trueColor('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('views')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('published_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published']),
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name'),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Top story'),
                Tables\Filters\TernaryFilter::make('is_breaking')->label('Breaking'),
                Tables\Filters\TernaryFilter::make('is_trending')->label('Trending'),
                Tables\Filters\Filter::make('seo_poor')
                    ->label('Poor SEO (<50)')
                    ->query(fn ($query) => $query->where('seo_score', '<', 50)),
                Tables\Filters\Filter::make('seo_unanalyzed')
                    ->label('Not analyzed')
                    ->query(fn ($query) => $query->whereNull('seo_score')),
            ])
            ->actions([
                Tables\Actions\Action::make('push_notify')
                    ->label('Push to phones')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->visible(fn (Post $r) => $r->status === 'published')
                    ->requiresConfirmation()
                    ->modalHeading('Send push notification')
                    ->modalIcon('heroicon-o-bell-alert')
                    ->modalDescription(fn (Post $r) => new HtmlString(
                        'Send <strong>' . e(Str::limit($r->title, 70)) . '</strong> to all '
                        . '<strong>' . \App\Models\PushSubscription::count() . '</strong> subscribed device(s) right now?'
                        . ($r->push_notified_at
                            ? '<br><span style="color:#d97706">Heads up: this post was already pushed once. Sending again will re-notify everyone.</span>'
                            : '')
                    ))
                    ->modalSubmitActionLabel('Send now')
                    ->action(fn (Post $record) => self::pushToPhones($record)),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('analyze_seo')
                        ->label('Optimize SEO (AI)')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription('AI fills blank meta fields and re-scores each selected post. Hand-written meta is kept.')
                        ->action(function (Collection $records) {
                            $optimizer = app(\App\Services\SeoOptimizer::class);
                            $done = 0;
                            foreach ($records as $post) {
                                try {
                                    $optimizer->optimizePost($post);
                                    $done++;
                                } catch (\Throwable $e) {
                                    report($e);
                                }
                            }
                            Notification::make()
                                ->title("Optimized {$done} of " . $records->count() . ' post(s)')
                                ->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Send a manual web-push for this post to every subscribed device, and report
     * the result as an admin toast. Shared by the table row action and the Edit-page
     * header button so both behave identically.
     */
    public static function pushToPhones(Post $record): void
    {
        if (blank(config('webpush.vapid.public_key')) || blank(config('webpush.vapid.private_key'))) {
            Notification::make()
                ->title('Push not configured')
                ->body('VAPID keys are missing, so web push is disabled on this site.')
                ->danger()->send();

            return;
        }

        $total = \App\Models\PushSubscription::count();
        if ($total === 0) {
            Notification::make()
                ->title('No subscribers yet')
                ->body('Nobody has enabled notifications, so there is nothing to send to.')
                ->warning()->send();

            return;
        }

        $sent = app(\App\Services\PushSender::class)
            ->sendToAll(\App\Jobs\SendNewPostNotification::payloadFor($record));

        // Record the push so the automatic notifier respects the interval and
        // won't immediately re-notify about the same story.
        $record->forceFill(['push_notified_at' => now()])->saveQuietly();
        \App\Models\Setting::put('last_push_at', now()->toDateTimeString());

        Notification::make()
            ->title($sent > 0 ? "Push sent to {$sent} of {$total} device(s)" : 'Push not delivered')
            ->body($sent > 0
                ? null
                : 'All stored subscriptions were expired or unreachable (they have been pruned).')
            ->color($sent > 0 ? 'success' : 'warning')
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
