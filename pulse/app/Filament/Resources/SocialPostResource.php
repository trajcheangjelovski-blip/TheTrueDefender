<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialPostResource\Pages;
use App\Models\SocialPost;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SocialPostResource extends Resource
{
    protected static ?string $model = SocialPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Social Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('post.title')->limit(50)->weight('bold')->label('Post'),
                Tables\Columns\TextColumn::make('channel.name')->badge()->label('Channel'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('external_url')->label('Link')
                    ->url(fn (SocialPost $r) => $r->external_url, true)
                    ->formatStateUsing(fn ($state) => $state ? 'view ↗' : '—')
                    ->color('info'),
                Tables\Columns\TextColumn::make('error')->limit(40)->placeholder('—')->color('danger')->tooltip(fn (SocialPost $r) => $r->error),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['sent' => 'Sent', 'failed' => 'Failed']),
                Tables\Filters\SelectFilter::make('social_channel_id')
                    ->relationship('channel', 'name')->label('Channel'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialPosts::route('/'),
        ];
    }
}
