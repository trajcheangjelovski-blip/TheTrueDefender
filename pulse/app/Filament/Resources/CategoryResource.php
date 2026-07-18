<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) =>
                        $operation === 'create' ? $set('slug', Str::slug($state)) : null),

                Forms\Components\TextInput::make('slug')->required(),

                Forms\Components\ColorPicker::make('color')->default('#e33b4e'),

                Forms\Components\TextInput::make('icon')
                    ->label('Emoji icon')
                    ->maxLength(16)
                    ->placeholder('🏛️'),

                Forms\Components\Select::make('layout')
                    ->options([
                        'feature' => 'Feature (big story + side list)',
                        'overlay' => 'Overlay tiles',
                        'rows' => 'Horizontal rows',
                        'briefs' => 'Text briefs',
                        'quotes' => 'Quote cards',
                    ])
                    ->default('rows')
                    ->native(false)
                    ->required(),

                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),

                Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull(),

                Forms\Components\Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('icon')->label(''),
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\ColorColumn::make('color'),
                Tables\Columns\TextColumn::make('layout')->badge(),
                Tables\Columns\TextColumn::make('posts_count')->counts('posts')->label('Posts'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
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
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
