<?php

namespace App\Filament\Resources\RoutingRules\Tables;

use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\RoutingManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoutingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordActions([
                Action::make('toggle')
                    ->label(fn ($record) => $record->enabled ? 'Pasifleştir' : 'Aktifleştir')
                    ->icon(fn ($record) => $record->enabled ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->visible(fn ($record) => (bool) $record->cf_id)
                    ->action(function ($record) {
                        try {
                            (new RoutingManager(Filament::getTenant()))->toggleRule($record, ! $record->enabled);
                        } catch (CloudflareException $e) {
                            Notification::make()->title('Güncellenemedi')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Kural güncellendi')->success()->send();
                    }),

                Action::make('delete')
                    ->label('Sil')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            (new RoutingManager(Filament::getTenant()))->deleteRule($record);
                        } catch (CloudflareException $e) {
                            Notification::make()->title('Silinemedi')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Kural silindi')->success()->send();
                    }),
            ])
            ->columns([
                TextColumn::make('domain.name')
                    ->label('Domain')
                    ->sortable(),

                TextColumn::make('matcher')
                    ->label('Adres / eşleşme')
                    ->description(fn ($record) => $record->is_catch_all ? 'catch-all' : null)
                    ->searchable(),

                TextColumn::make('actions')
                    ->label('Aksiyon')
                    ->formatStateUsing(function ($state) {
                        $actions = collect(is_array($state) ? $state : [])
                            ->map(fn ($a) => $a['type'] ?? '?');

                        return $actions->isNotEmpty() ? $actions->implode(', ') : '—';
                    }),

                IconColumn::make('enabled')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                //
            ]);
    }
}
