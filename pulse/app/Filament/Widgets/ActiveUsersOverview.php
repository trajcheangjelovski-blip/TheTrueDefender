<?php

namespace App\Filament\Widgets;

use App\Models\PushSubscription;
use App\Models\Subscriber;
use App\Models\Visit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ActiveUsersOverview extends BaseWidget
{
    protected static ?int $sort = 0;

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // Real-time readers from the presence heartbeat (public/js/engage.js).
        $activeNow = Visit::active(2)->count();
        $activeToday = Visit::where('last_seen_at', '>=', now()->startOfDay())->count();

        $onMobile = Visit::active(2)->where('device', 'mobile')->count();
        $onDesktop = Visit::active(2)->where('device', 'desktop')->count();

        $pushDevices = PushSubscription::count();
        $pushToday = PushSubscription::whereDate('created_at', today())->count();

        $subscribers = Subscriber::where('status', 'subscribed')->count();
        $subsThisWeek = Subscriber::where('status', 'subscribed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            Stat::make('Active now', number_format($activeNow))
                ->description($onMobile . ' mobile · ' . $onDesktop . ' desktop')
                ->descriptionIcon('heroicon-m-signal')
                ->color($activeNow > 0 ? 'success' : 'gray'),

            Stat::make('Active today', number_format($activeToday))
                ->description('unique readers since midnight')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Push devices', number_format($pushDevices))
                ->description('+' . number_format($pushToday) . ' today')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color('info'),

            Stat::make('Newsletter subscribers', number_format($subscribers))
                ->description('+' . number_format($subsThisWeek) . ' this week')
                ->descriptionIcon('heroicon-m-envelope-open')
                ->color('info'),
        ];
    }
}
