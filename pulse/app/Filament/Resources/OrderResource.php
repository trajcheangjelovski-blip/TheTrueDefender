<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Order')->columns(2)->schema([
                Forms\Components\TextInput::make('order_number')->disabled(),
                Forms\Components\Select::make('status')
                    ->options(array_combine(Order::STATUSES, array_map('ucfirst', Order::STATUSES)))
                    ->required()->native(false),
            ]),

            Forms\Components\Section::make('Customer')->columns(2)->schema([
                Forms\Components\TextInput::make('customer_name')->required(),
                Forms\Components\TextInput::make('customer_email')->email()->required(),
                Forms\Components\TextInput::make('customer_phone'),
                Forms\Components\Textarea::make('shipping_address')->required()->columnSpanFull(),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Items')->schema([
                Forms\Components\Repeater::make('items')
                    ->relationship()
                    ->columns(4)
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->schema([
                        Forms\Components\TextInput::make('name')->disabled()->columnSpan(2),
                        Forms\Components\TextInput::make('quantity')->disabled(),
                        Forms\Components\TextInput::make('line_total')->prefix('$')->disabled(),
                    ]),
            ]),

            Forms\Components\Section::make('Totals')->columns(3)->schema([
                Forms\Components\TextInput::make('subtotal')->prefix('$')->disabled(),
                Forms\Components\TextInput::make('shipping')->prefix('$'),
                Forms\Components\TextInput::make('total')->prefix('$')->disabled(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('customer_name')->searchable()
                    ->description(fn (Order $r) => $r->customer_email),
                Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Items'),
                Tables\Columns\TextColumn::make('total')->money('usd')->sortable(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn () => 'Paid')
                    ->color('success')
                    ->placeholder('Unpaid')
                    ->tooltip(fn ($record) => $record->payment_method ? 'via ' . $record->payment_method : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'info',
                        'shipped' => 'primary',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(array_combine(Order::STATUSES, array_map('ucfirst', Order::STATUSES))),
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
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
