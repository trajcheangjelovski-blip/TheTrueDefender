<?php

namespace App\Filament\Resources\PageSeoResource\Pages;

use App\Filament\Resources\PageSeoResource;
use App\Jobs\OptimizeSeoJob;
use App\Models\PageSeo;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPageSeos extends ListRecords
{
    protected static string $resource = PageSeoResource::class;

    public function mount(): void
    {
        parent::mount();
        // Make sure a tracked row exists for every static page.
        PageSeo::ensureSeeded();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('optimize_all_pages')
                ->label('AI-optimize all pages')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('AI SEO optimization for the whole site')
                ->modalDescription('Queues every page: AI reads the live page, fills blank meta and re-scores. '
                    . 'Hand-written meta is never overwritten. Needs the queue worker running.')
                ->action(function () {
                    PageSeo::ensureSeeded();
                    PageSeo::pluck('id')->each(fn ($id) => OptimizeSeoJob::dispatch('page', $id));

                    Notification::make()
                        ->title('Queued SEO optimization for all pages')
                        ->body('Scores and meta will fill in as the queue processes. Refresh in a minute.')
                        ->success()->send();
                }),
        ];
    }
}
