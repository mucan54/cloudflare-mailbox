<?php

namespace App\Filament\Resources\RoutingRules\Pages;

use App\Filament\Resources\RoutingRules\RoutingRuleResource;
use App\Filament\Support\FullSyncAction;
use Filament\Resources\Pages\ListRecords;

class ListRoutingRules extends ListRecords
{
    protected static string $resource = RoutingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FullSyncAction::make(),
        ];
    }
}
