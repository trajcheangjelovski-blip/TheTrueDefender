<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Jobs\OptimizeSeoJob;
use App\Models\Post;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('optimize_all_seo')
                ->label('AI-optimize all posts')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('AI SEO optimization for every post')
                ->modalDescription('Queues every post: AI fills blank meta (title/description/keyword) and re-scores. '
                    . 'Hand-written meta is never overwritten. Runs in the background — needs the queue worker running.')
                ->action(function () {
                    $count = 0;
                    Post::query()->orderBy('id')->pluck('id')->each(function ($id) use (&$count) {
                        OptimizeSeoJob::dispatch('post', $id);
                        $count++;
                    });

                    Notification::make()
                        ->title("Queued SEO optimization for {$count} post(s)")
                        ->body('Scores and meta will fill in as the queue processes. Refresh in a few minutes.')
                        ->success()->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
