<?php

namespace App\Filament\Support;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\AccountSynchronizer;
use App\Services\Cloudflare\CloudflareException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Full Sync" — pulls zones, routing rules and destination addresses for the
 * current tenant from Cloudflare into the local database.
 */
class FullSyncAction
{
    public static function make(string $name = 'fullSync'): Action
    {
        return Action::make($name)
            ->label('Full Sync')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function () {
                /** @var CloudflareAccount $account */
                $account = Filament::getTenant();

                if (! $account->isConnected()) {
                    Notification::make()
                        ->title('Önce Cloudflare hesabını bağlayın')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $result = (new AccountSynchronizer($account))->full();
                } catch (CloudflareException $e) {
                    Notification::make()
                        ->title('Senkron başarısız')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $counts = $result['counts'];
                $errors = $result['errors'];
                $summary = "Domain: {$counts['domains']} · Adres: {$counts['addresses']} · Kural: {$counts['rules']}";

                if (empty($errors)) {
                    Notification::make()->title('Senkron tamamlandı')->body($summary)->success()->send();

                    return;
                }

                // Partial success — most commonly a missing Email Routing permission.
                $failed = implode(', ', array_keys($errors));
                Notification::make()
                    ->title('Senkron kısmen tamamlandı')
                    ->body($summary."\n\nAlınamayan: {$failed}. Token’ınızda "
                        .'“Email Routing: Edit” izni eksik olabilir — Cloudflare’de token’ı '
                        .'düzenleyip bu izni ekleyin, sonra tekrar deneyin.')
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }
}
