<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Admins';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_users') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),

                Forms\Components\TextInput::make('password')
                    ->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state)) // model casts 'password' => 'hashed'
                    ->helperText('Leave blank when editing to keep the current password.'),

                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()->preload()->searchable()
                    ->helperText('Roles define what this admin can access (manage in Roles).')
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Public author profile')
                ->description('When enabled, this person appears as a named byline with a /author page. Strengthens E-E-A-T for Google/AdSense.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_author')
                        ->label('Show as public author')
                        ->helperText('Off = admin-only account, never shown publicly.')
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')
                        ->helperText('URL: /author/{slug}. Lowercase, hyphenated.')
                        ->unique(ignoreRecord: true)
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                    Forms\Components\TextInput::make('title')
                        ->label('Role / title')
                        ->placeholder('Editor-in-Chief')
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                    Forms\Components\Textarea::make('bio')
                        ->rows(3)->maxLength(600)
                        ->helperText('2–4 sentences. Real, specific, first-person-neutral.')
                        ->visible(fn (Forms\Get $get) => $get('is_author'))
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('credentials')
                        ->rows(2)->maxLength(600)
                        ->label('Experience / expertise')
                        ->visible(fn (Forms\Get $get) => $get('is_author'))
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('photo_path')
                        ->label('Photo')->image()->avatar()
                        ->directory('authors')->disk('public')
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                    Forms\Components\TextInput::make('public_email')
                        ->email()->label('Public contact email')
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                    Forms\Components\TextInput::make('x_url')
                        ->url()->label('X / Twitter URL')
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                    Forms\Components\TextInput::make('linkedin_url')
                        ->url()->label('LinkedIn URL')
                        ->visible(fn (Forms\Get $get) => $get('is_author')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('email')->searchable()->color('gray'),
                Tables\Columns\TextColumn::make('roles.name')->badge()->label('Roles')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y')->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $r) => $r->id !== auth()->id()), // can't delete yourself
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
