<?php

namespace App\Filament\Widgets;

use App\Models\Comment;
use App\Models\Post;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SiteEngagementStats extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        // On-site engagement funnel: impressions -> clicks -> views.
        $views = (int) Post::sum('views');
        $impressions = (int) Post::sum('impressions');
        $clicks = (int) Post::sum('clicks');

        $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

        $published = Post::published()->count();
        $publishedToday = Post::published()
            ->whereDate('published_at', today())
            ->count();

        $breaking = Post::breakingActive()->count();

        $comments = Comment::approved()->count();
        $pending = Comment::where('status', 'pending')->count();

        return [
            Stat::make('Total views', number_format($views))
                ->description(number_format($clicks) . ' clicks from ' . number_format($impressions) . ' impressions')
                ->descriptionIcon('heroicon-m-eye')
                ->color('success'),

            Stat::make('Click-through rate', number_format($ctr, 1) . '%')
                ->description('clicks ÷ impressions')
                ->descriptionIcon('heroicon-m-cursor-arrow-rays')
                ->color($ctr >= 2 ? 'success' : 'warning'),

            Stat::make('Published posts', number_format($published))
                ->description($publishedToday . ' today · ' . $breaking . ' breaking now')
                ->descriptionIcon('heroicon-m-newspaper')
                ->color('primary'),

            Stat::make('Approved comments', number_format($comments))
                ->description($pending . ' awaiting moderation')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($pending > 0 ? 'warning' : 'gray'),
        ];
    }
}
