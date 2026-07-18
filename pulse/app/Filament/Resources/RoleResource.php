<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_users') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->unique(ignoreRecord: true)
                ->helperText('e.g. editor, shop-manager, contributor'),

            Forms\Components\CheckboxList::make('permissions')
                ->relationship('permissions', 'name')
                ->options(fn () => \Spatie\Permission\Models\Permission::pluck('name', 'name'))
                ->descriptions([
                    'manage_posts' => 'Create/edit posts & categories',
                    'manage_shop' => 'Products & orders',
                    'manage_audience' => 'Subscribers & push',
                    'manage_automation' => 'News feeds, ingest, social channels',
                    'manage_ads' => 'Ad placements',
                    'manage_settings' => 'AI & site settings',
                    'manage_users' => 'Admins & roles',
                ])
                ->columns(2)
                ->bulkToggleable()
                ->helperText('The "rules" this role grants. The admin role always has everything.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Admins'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $r) => $r->name !== 'admin'), // protect the super-admin role
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
