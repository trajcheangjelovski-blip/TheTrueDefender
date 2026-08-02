<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagResource\Pages;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Topics';

    protected static ?string $modelLabel = 'topic';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(40)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) =>
                    $operation === 'create' ? $set('slug', \Illuminate\Support\Str::slug($state)) : null),

            Forms\Components\TextInput::make('slug')
                ->required()
                ->helperText('URL: /topic/your-slug'),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->maxLength(500)
                ->helperText('Optional intro + meta description shown on the topic hub page. Great for SEO.'),

            Forms\Components\Toggle::make('is_active')
                ->default(true)
                ->helperText('Off hides the hub page and its chips.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('slug')->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('posts_count')
                    ->label('Stories')
                    ->counts('posts')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('M j, Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View hub')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Tag $r) => route('topic.show', $r))
                    ->openUrlInNewTab(),
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
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
