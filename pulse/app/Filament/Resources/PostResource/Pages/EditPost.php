<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Models\Post;
use App\Models\PushSubscription;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('push_notify')
                ->label('Push to phones')
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                ->visible(fn (Post $record) => $record->status === 'published')
                ->requiresConfirmation()
                ->modalHeading('Send push notification')
                ->modalIcon('heroicon-o-bell-alert')
                ->modalDescription(fn (Post $record) => new HtmlString(
                    'Send <strong>' . e(Str::limit($record->title, 70)) . '</strong> to all '
                    . '<strong>' . PushSubscription::count() . '</strong> subscribed device(s) right now?'
                    . ($record->push_notified_at
                        ? '<br><span style="color:#d97706">Heads up: this post was already pushed once. Sending again will re-notify everyone.</span>'
                        : '')
                ))
                ->modalSubmitActionLabel('Send now')
                ->action(fn (Post $record) => PostResource::pushToPhones($record)),
            Actions\DeleteAction::make(),
        ];
    }
}
