<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Services\SeoOptimizer;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /** Every new post is AI SEO-optimized automatically on creation. */
    protected function afterCreate(): void
    {
        $post = $this->record;

        // Skip if the editor already ran "Optimize with AI" inside the form.
        if ($post->seo_analyzed_at !== null) {
            return;
        }

        try {
            $analysis = app(SeoOptimizer::class)->optimizePost($post);

            Notification::make()
                ->title("Auto-SEO: {$analysis['score']}/100 ({$analysis['grade']})")
                ->body('Meta title, description and focus keyword were optimized by AI. Review them in the SEO section.')
                ->color($analysis['score'] >= 80 ? 'success' : ($analysis['score'] >= 50 ? 'warning' : 'danger'))
                ->send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
