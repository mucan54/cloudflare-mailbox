<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Filament\Support\SendingDnsGuide;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\MailClientDns;
use App\Services\Cloudflare\RoutingManager;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordActions([
                // Email Sending onboarding is dashboard-only (no Cloudflare API
                // for it), so we can't flip this on from here — deep-link the
                // operator straight to this account's Email Sending page.
                Action::make('enableSending')
                    ->label('Gönderimi aç')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn ($record) => (bool) $record->zone_id && ! $record->sending_enabled)
                    ->tooltip('Cloudflare panelinde Compute → Email Service → Email Sending → “Onboard Domain” sayfasını açar (bu adım API ile yapılamıyor).')
                    ->url(fn () => 'https://dash.cloudflare.com/'.Filament::getTenant()->account_id.'/email/sending')
                    ->openUrlInNewTab(),

                Action::make('sendingDns')
                    ->label('DNS kayıtları')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->visible(fn ($record) => (bool) $record->zone_id)
                    ->modalHeading(fn ($record) => $record->name.' — Gönderim DNS kayıtları')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Kapat')
                    ->modalContent(fn ($record): View => view(
                        'filament.sending-dns-guide',
                        SendingDnsGuide::build(Filament::getTenant(), $record),
                    )),

                Action::make('mailClientDns')
                    ->label('Mail istemci DNS')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Apple Mail/Outlook otomatik kurulumu için autodiscover/autoconfig/mail (CNAME), takvim/kişi (CalDAV/CardDAV SRV) ve SRV kayıtlarını bu domaine ekler.')
                    ->visible(fn ($record) => (bool) $record->zone_id && ((bool) config('cloudflare.mail_client.enabled') || (bool) config('cloudflare.mail_client.dav')))
                    ->action(function ($record) {
                        try {
                            app(MailClientDns::class)->provision($record);
                        } catch (\Throwable $e) {
                            Notification::make()->title('DNS oluşturulamadı')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Mail istemci DNS kayıtları eklendi')->success()->send();
                    }),

                Action::make('catchAllToWorker')
                    ->label('Catch-all → Worker')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->requiresConfirmation()
                    ->modalDescription('Bu domaine gelen tüm mailler gelen kutusu Worker’ına yönlendirilecek.')
                    ->visible(fn ($record) => (bool) $record->zone_id && $record->inbound_capture !== 'catch_all')
                    ->action(function ($record) {
                        $account = Filament::getTenant();

                        // The Worker must exist before it can be a catch-all target.
                        if (! $account->isWorkerDeployed()) {
                            Notification::make()
                                ->title('Önce Worker’ı deploy edin')
                                ->body('Bu domaini Worker’a bağlamadan önce üstteki “Deploy Worker” ile gelen mail Worker’ını deploy edin.')
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $workerName = (new WorkerDeployer($account))->workerName();
                        try {
                            (new RoutingManager($account))->setCatchAllToWorker($record, $workerName);
                        } catch (CloudflareException $e) {
                            Notification::make()->title('Ayarlanamadı')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Catch-all Worker’a ayarlandı')->success()->send();
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Zone')
                    ->badge()
                    ->sortable(),

                IconColumn::make('sending_enabled')
                    ->label('Gönderme')
                    ->boolean(),

                IconColumn::make('routing_enabled')
                    ->label('Alma')
                    ->boolean(),

                TextColumn::make('inbound_capture')
                    ->label('Gelen yakalama')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'catch_all' => 'Catch-all',
                        'per_address' => 'Adres bazlı',
                        default => 'Kapalı',
                    })
                    ->color(fn (string $state) => $state === 'none' ? 'gray' : 'success'),

                TextColumn::make('last_synced_at')
                    ->label('Son senkron')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}
