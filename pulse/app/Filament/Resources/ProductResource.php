<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make()->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) =>
                                $operation === 'create' ? $set('slug', Str::slug($state)) : null)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('slug')->required()->columnSpanFull(),
                        Forms\Components\TextInput::make('short_description')
                            ->label('Short description')
                            ->maxLength(300)
                            ->helperText('One-line summary shown prominently under the product name.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(4)
                            ->helperText('The main product description.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('details')
                            ->label('Product details')
                            ->rows(5)
                            ->helperText('Specs and details — materials, sizing, what\'s included. Put each detail on its own line to show as a bullet list.')
                            ->columnSpanFull(),
                    ]),

                    Forms\Components\Section::make('Variations')
                        ->description('Optional. Add color / size / style options with the same or a different price. '
                            . 'Leave empty for a single-option product. Leave a variant\'s price blank to use the base price above.')
                        ->icon('heroicon-o-swatch')
                        ->collapsible()
                        ->schema([
                            Forms\Components\Repeater::make('variants')
                                ->relationship()
                                ->hiddenLabel()
                                ->schema([
                                    Forms\Components\TextInput::make('color')->placeholder('e.g. Red'),
                                    Forms\Components\TextInput::make('size')->placeholder('e.g. Large'),
                                    Forms\Components\TextInput::make('style')->placeholder('e.g. Embroidered'),
                                    Forms\Components\TextInput::make('price')
                                        ->numeric()->prefix('$')
                                        ->placeholder('base price')
                                        ->helperText('Blank = base price.'),
                                    Forms\Components\TextInput::make('sale_price')->numeric()->prefix('$')->placeholder('optional'),
                                    Forms\Components\TextInput::make('stock')->numeric()->default(0),
                                    Forms\Components\FileUpload::make('image')
                                        ->image()->imageEditor()->directory('products')
                                        ->helperText('Optional. Shown when this variant is selected. Transparency preserved; under 300 KB.')
                                        ->saveUploadedFileUsing(fn ($file) => app(\App\Services\ImageProcessor::class)
                                            ->storeUpload($file, 'products', false, true))
                                        ->columnSpanFull(),
                                    Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
                                ])
                                ->columns(3)
                                ->itemLabel(fn (array $state): ?string => collect([$state['color'] ?? null, $state['size'] ?? null, $state['style'] ?? null])
                                    ->filter()->implode(' / ') ?: 'New variation')
                                ->collapsed()
                                ->collapsible()
                                ->cloneable()
                                ->reorderable('sort_order')
                                ->orderColumn('sort_order')
                                ->addActionLabel('Add a variation')
                                ->defaultItems(0),
                        ]),
                ])->columnSpan(2),

                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Pricing')->schema([
                        Forms\Components\TextInput::make('price')
                            ->numeric()->prefix('$')->required()
                            ->helperText('Set to 0 for a "FREE — just pay shipping" product.'),
                        Forms\Components\TextInput::make('sale_price')
                            ->numeric()->prefix('$')
                            ->helperText('Optional. Shown as the discounted price.'),
                        Forms\Components\TextInput::make('shipping_price')
                            ->label('Shipping & handling')
                            ->numeric()->prefix('$')->default(0)
                            ->helperText('Charged per item. For free products this is what the customer pays.'),
                    ]),
                    Forms\Components\Section::make('Inventory')->schema([
                        Forms\Components\TextInput::make('sku'),
                        Forms\Components\Toggle::make('track_stock')->label('Track stock'),
                        Forms\Components\TextInput::make('stock')->numeric()->default(0),
                    ]),
                    Forms\Components\Section::make('Display')->schema([
                        Forms\Components\Toggle::make('watermark_image')
                            ->label('Add TheTrueDefender watermark')
                            ->helperText('Stamps the brand on the photo. Off by default for products.')
                            ->default(false)->dehydrated(false),
                        Forms\Components\FileUpload::make('image')->image()->imageEditor()->directory('products')
                            ->helperText('Transparency (PNG) is preserved; compressed to under 300 KB. Upload transparent PNGs for the best look on the dark cards.')
                            ->saveUploadedFileUsing(fn ($file, Forms\Get $get) => app(\App\Services\ImageProcessor::class)
                                ->storeUpload($file, 'products', (bool) $get('watermark_image'), true)),
                        Forms\Components\TextInput::make('image_icon')->label('Emoji fallback')->placeholder('🧢')->maxLength(16),
                        Forms\Components\TextInput::make('tag')->placeholder('Best Seller / New')->helperText('Optional corner badge.'),
                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        Forms\Components\Toggle::make('is_active')->label('Active (visible in shop)')->default(true),
                    ]),
                ])->columnSpan(1),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('image_icon')->label(''),
                Tables\Columns\ImageColumn::make('image')->label('')->circular(),
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold')->limit(40),
                Tables\Columns\TextColumn::make('price')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => (float) $state == 0.0 ? 'FREE' : '$' . number_format($state, 2))
                    ->color(fn ($state) => (float) $state == 0.0 ? 'success' : null),
                Tables\Columns\TextColumn::make('sale_price')->money('usd')->placeholder('—'),
                Tables\Columns\TextColumn::make('shipping_price')->label('Shipping')->money('usd')->sortable(),
                Tables\Columns\TextColumn::make('stock')->badge()->sortable(),
                Tables\Columns\TextColumn::make('tag')->badge()->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
