<?php

namespace App\Filament\Support;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Deploy (or redeploy) the inbound Email Worker for the current tenant. Requires
 * node + wrangler in the runtime and a token with Workers Scripts: Edit.
 */
class DeployWorkerAction
{
    public static function make(string $name = 'deployWorker'): Action
    {
        return Action::make($name)
            ->label(__('Deploy Worker'))
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Deploys the inbound mail Worker to Cloudflare from this server (needs Workers Scripts: Edit on your token).'))
            ->action(function () {
                /** @var CloudflareAccount $account */
                $account = Filament::getTenant();

                if (! $account->isConnected()) {
                    Notification::make()->title(__('Connect your Cloudflare account first'))->warning()->send();

                    return;
                }

                if (! $account->isSynced()) {
                    Notification::make()->title(__('Run Full Sync first'))->warning()->send();

                    return;
                }

                try {
                    (new WorkerDeployer($account))->deploy();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Worker deploy failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Worker deployed'))
                    ->body(__('Now set a domain to catch-all → Worker to start receiving mail.'))
                    ->success()
                    ->send();
            });
    }
}
