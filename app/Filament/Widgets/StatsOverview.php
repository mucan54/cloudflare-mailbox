<?php

namespace App\Filament\Widgets;

use App\Models\CloudflareAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        /** @var CloudflareAccount $account */
        $account = Filament::getTenant();

        $unread = $account->emails()->whereNull('read_at')->count();
        $since = now()->subDay();

        $sent = $account->sentEmails()->where('sent_at', '>=', $since);
        $sentTotal = (clone $sent)->count();
        $delivered = (clone $sent)->where('status', 'delivered')->count();
        $bounced = (clone $sent)->whereIn('status', ['bounced', 'failed'])->count();

        return [
            Stat::make(__('Unread mail'), $unread)
                ->description(__('in the inbox'))
                ->icon('heroicon-o-inbox')
                ->color($unread > 0 ? 'warning' : 'gray'),

            Stat::make(__('Sent (24h)'), $sentTotal)
                ->description($delivered.' '.__('delivered').' · '.$bounced.' '.__('bounced/failed'))
                ->icon('heroicon-o-paper-airplane')
                ->color($bounced > 0 ? 'danger' : 'success'),

            Stat::make(__('Domains'), $account->domains()->count())
                ->description($account->mailboxes()->count().' '.__('mailboxes'))
                ->icon('heroicon-o-globe-alt')
                ->color('primary'),
        ];
    }
}
