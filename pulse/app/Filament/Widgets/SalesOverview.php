<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Post;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $paidStatuses = ['paid', 'shipped', 'completed'];

        $revenue = Order::whereIn('status', $paidStatuses)->sum('total');
        $monthRevenue = Order::whereIn('status', $paidStatuses)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $totalOrders = Order::count();
        $pending = Order::where('status', 'pending')->count();

        return [
            Stat::make('Revenue (paid)', '$' . number_format($revenue, 2))
                ->description('$' . number_format($monthRevenue, 2) . ' this month')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Orders', number_format($totalOrders))
                ->description($pending . ' pending')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color($pending > 0 ? 'warning' : 'primary'),

            Stat::make('Products', number_format(Product::active()->count()))
                ->description('active in shop')
                ->descriptionIcon('heroicon-m-shopping-bag'),

            Stat::make('Published posts', number_format(Post::published()->count()))
                ->description('live on the site')
                ->descriptionIcon('heroicon-m-newspaper'),

            Stat::make('Subscribers', number_format(Subscriber::where('status', 'subscribed')->count()))
                ->description(PushSubscription::count() . ' push devices')
                ->descriptionIcon('heroicon-m-envelope')
                ->color('info'),
        ];
    }
}
