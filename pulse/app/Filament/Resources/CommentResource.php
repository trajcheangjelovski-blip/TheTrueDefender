<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommentResource\Pages;
use App\Models\Comment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_audience') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Comment::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Comment')->schema([
                Forms\Components\Placeholder::make('post')
                    ->content(fn (Comment $r) => $r->post?->title ?? '—'),
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\TextInput::make('surname')->required(),
                Forms\Components\Textarea::make('body')->label('Comment / opinion text')->rows(4)->required()->columnSpanFull(),
                Forms\Components\DateTimePicker::make('created_at')
                    ->label('Posted at')
                    ->seconds(false)
                    ->helperText('Edit when this comment appears to have been posted.'),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'spam' => 'Spam',
                    ])->required()->native(false),
                Forms\Components\Placeholder::make('ai_reason')
                    ->label('AI moderation verdict')
                    ->content(fn (Comment $r) => $r->ai_reason ?: 'Not auto-moderated.')
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Private contact details')
                ->description('Kept on file — never shown publicly.')
                ->schema([
                    Forms\Components\TextInput::make('email')->email()->required(),
                    Forms\Components\TextInput::make('phone')->required(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Commenter')->weight('bold')
                    ->state(fn (Comment $r) => ($r->parent_id ? '↩ ' : '') . $r->display_name)
                    ->description(fn (Comment $r) => ($r->parent_id ? '[reply] ' : '') . Str::limit($r->body, 70)),
                Tables\Columns\TextColumn::make('post.title')->label('On post')->limit(30),
                Tables\Columns\TextColumn::make('email')->label('Email (private)')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('phone')->label('Phone (private)')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'spam' => 'gray',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Comment $r) => $r->ai_reason),
                Tables\Columns\IconColumn::make('moderated_at')
                    ->label('AI')
                    ->boolean()
                    ->trueIcon('heroicon-s-sparkles')->trueColor('primary')
                    ->falseIcon('heroicon-o-user')->falseColor('gray')
                    ->getStateUsing(fn (Comment $r) => $r->moderated_at !== null)
                    ->tooltip(fn (Comment $r) => $r->ai_reason ?? 'Not AI-moderated'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'spam' => 'Spam'])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-m-check-circle')->color('success')
                    ->visible(fn (Comment $r) => $r->status !== 'approved')
                    ->action(fn (Comment $r) => $r->update(['status' => 'approved'])),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-m-x-circle')->color('danger')
                    ->visible(fn (Comment $r) => $r->status !== 'rejected')
                    ->action(fn (Comment $r) => $r->update(['status' => 'rejected'])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve')
                        ->label('Approve')->icon('heroicon-m-check-circle')->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'approved']))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComments::route('/'),
            'edit' => Pages\EditComment::route('/{record}/edit'),
        ];
    }
}
