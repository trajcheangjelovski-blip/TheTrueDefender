<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateResource\Pages;
use App\Models\Affiliate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AffiliateResource extends Resource
{
    protected static ?string $model = Affiliate::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_affiliates') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Affiliate::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Affiliate')->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('email')->email()->required()->maxLength(180)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->password()->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText('Leave blank to keep the current password.'),
                        Forms\Components\TextInput::make('website')->label('Promotes on')->maxLength(300),
                        Forms\Components\Textarea::make('notes')->rows(3)
                            ->helperText('Their application message / your internal notes.'),
                    ])->columns(2),

                    Forms\Components\Section::make('Stats')
                        ->schema([
                            Forms\Components\Placeholder::make('stats')
                                ->hiddenLabel()
                                ->content(function (?Affiliate $record) {
                                    if (! $record) {
                                        return 'Available after creation.';
                                    }

                                    return new \Illuminate\Support\HtmlString(
                                        '<div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.9rem">'
                                        . '<span><strong>' . number_format($record->validClicksCount()) . '</strong> valid clicks</span>'
                                        . '<span><strong>$' . number_format($record->clickEarnings(), 2) . '</strong> traffic earnings</span>'
                                        . '<span><strong>$' . number_format($record->saleEarnings(), 2) . '</strong> sale commissions</span>'
                                        . '<span><strong>$' . number_format($record->totalPaid(), 2) . '</strong> paid out</span>'
                                        . '<span><strong>$' . number_format($record->balance(), 2) . '</strong> balance due</span>'
                                        . '</div>'
                                    );
                                }),
                        ])->hiddenOn('create'),
                ])->columnSpan(2),

                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Status')->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending review',
                                'active' => 'Active',
                                'suspended' => 'Suspended',
                            ])
                            ->required()->native(false),
                        Forms\Components\Placeholder::make('code_display')
                            ->label('Referral link')
                            ->content(fn (?Affiliate $record) => $record?->referralUrl() ?? 'Generated on save'),
                        Forms\Components\TextInput::make('payout_method')
                            ->label('Payout method')
                            ->placeholder('PayPal email, IBAN…'),
                    ]),

                    Forms\Components\Section::make('Rate overrides')
                        ->description('Leave blank to use the global rates from AI & Ads Settings.')
                        ->schema([
                            Forms\Components\TextInput::make('rate_per_1000')
                                ->label('RPM (your $ per 1000 visits)')
                                ->numeric()->step('0.01')->placeholder('global'),
                            Forms\Components\TextInput::make('share_pct')
                                ->label('Their share %')
                                ->numeric()->minValue(0)->maxValue(100)->placeholder('global'),
                            Forms\Components\TextInput::make('sale_commission_pct')
                                ->label('Sale commission %')
                                ->numeric()->minValue(0)->maxValue(100)->placeholder('global'),
                        ]),
                ])->columnSpan(1),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold')
                    ->description(fn (Affiliate $r) => $r->email),
                Tables\Columns\TextColumn::make('code')->label('Code')->copyable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('valid_clicks')
                    ->label('Valid clicks')
                    ->state(fn (Affiliate $r) => number_format($r->validClicksCount())),
                Tables\Columns\TextColumn::make('earned')
                    ->label('Earned')
                    ->state(fn (Affiliate $r) => '$' . number_format($r->totalEarned(), 2)),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance due')
                    ->state(fn (Affiliate $r) => '$' . number_format($r->balance(), 2))
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->date('M j, Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Affiliate $r) => $r->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (Affiliate $r) {
                        $r->update(['status' => 'active']);
                        Notification::make()->title("{$r->name} approved")->success()->send();
                    }),
                Tables\Actions\Action::make('payout')
                    ->label('Record payout')
                    ->icon('heroicon-m-banknotes')
                    ->visible(fn (Affiliate $r) => $r->status === 'active' && $r->balance() > 0)
                    ->form(fn (Affiliate $r) => [
                        Forms\Components\TextInput::make('amount')
                            ->numeric()->step('0.01')->required()
                            ->default($r->balance())
                            ->helperText('Balance due: $' . number_format($r->balance(), 2)),
                        Forms\Components\TextInput::make('method')
                            ->default($r->payout_method)
                            ->placeholder('PayPal, bank transfer…'),
                        Forms\Components\TextInput::make('note')->placeholder('Reference / transaction ID'),
                    ])
                    ->action(function (Affiliate $r, array $data) {
                        $r->payouts()->create([
                            'amount' => $data['amount'],
                            'method' => $data['method'] ?? null,
                            'note' => $data['note'] ?? null,
                            'paid_at' => now(),
                        ]);
                        // Referred-sale commissions that are approved are now settled.
                        $r->conversions()->where('status', 'approved')->update(['status' => 'paid']);
                        Notification::make()
                            ->title('Payout of $' . number_format((float) $data['amount'], 2) . ' recorded')
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
            'index' => Pages\ListAffiliates::route('/'),
            'create' => Pages\CreateAffiliate::route('/create'),
            'edit' => Pages\EditAffiliate::route('/{record}/edit'),
        ];
    }
}
