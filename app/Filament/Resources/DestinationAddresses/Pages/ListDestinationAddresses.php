<?php

namespace App\Filament\Resources\DestinationAddresses\Pages;

use App\Filament\Resources\DestinationAddresses\DestinationAddressResource;
use App\Filament\Support\FullSyncAction;
use Filament\Resources\Pages\ListRecords;

class ListDestinationAddresses extends ListRecords
{
    protected static string $resource = DestinationAddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FullSyncAction::make(),
        ];
    }
}
