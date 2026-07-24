<?php

namespace App\Filament\Resources\DestinationAddresses\Tables;

use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\RoutingManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationAddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordActions([
                Action::make('delete')
                    ->label('Sil')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            (new RoutingManager(Filament::getTenant()))->deleteDestinationAddress($record);
                        } catch (CloudflareException $e) {
                            Notification::make()->title('Silinemedi')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Adres silindi')->success()->send();
                    }),
            ])
            ->columns([
                TextColumn::make('email')
                    ->label('Hedef adres')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('verified_at')
                    ->label('Doğrulanmış')
                    ->boolean()
                    ->state(fn ($record) => $record->verified_at !== null),

                TextColumn::make('created_at')
                    ->label('Eklendi')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('email');
    }
}
