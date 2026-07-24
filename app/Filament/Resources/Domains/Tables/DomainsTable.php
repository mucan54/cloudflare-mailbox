<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\RoutingManager;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordActions([
                Action::make('catchAllToWorker')
                    ->label('Catch-all → Worker')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->requiresConfirmation()
                    ->modalDescription('Bu domaine gelen tüm mailler gelen kutusu Worker’ına yönlendirilecek.')
                    ->visible(fn ($record) => (bool) $record->zone_id && $record->inbound_capture !== 'catch_all')
                    ->action(function ($record) {
                        $account = Filament::getTenant();
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
