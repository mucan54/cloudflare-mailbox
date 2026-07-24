<?php

namespace App\Filament\Resources\DestinationAddresses\Pages;

use App\Filament\Resources\DestinationAddresses\DestinationAddressResource;
use App\Filament\Support\FullSyncAction;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\RoutingManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDestinationAddresses extends ListRecords
{
    protected static string $resource = DestinationAddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Adres ekle')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('email')->label('Hedef e-posta')->email()->required(),
                ])
                ->action(function (array $data) {
                    try {
                        (new RoutingManager(Filament::getTenant()))->addDestinationAddress($data['email']);
                    } catch (CloudflareException $e) {
                        Notification::make()->title('Eklenemedi')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Adres eklendi')
                        ->body('Cloudflare doğrulama maili gönderdi; onaylanana kadar pasif kalır.')
                        ->success()
                        ->send();
                }),

            FullSyncAction::make(),
        ];
    }
}
