<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Filament\Support\SendingDnsGuide;
use App\Services\Cloudflare\CloudflareException;
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
