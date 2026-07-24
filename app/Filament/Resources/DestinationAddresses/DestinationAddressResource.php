<?php

namespace App\Filament\Resources\DestinationAddresses;

use App\Filament\Resources\DestinationAddresses\Pages\ListDestinationAddresses;
use App\Filament\Resources\DestinationAddresses\Tables\DestinationAddressesTable;
use App\Models\DestinationAddress;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DestinationAddressResource extends Resource
{
    protected static ?string $model = DestinationAddress::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Cloudflare';

    public static function getNavigationLabel(): string
    {
        return __('Destination addresses');
    }

    public static function getModelLabel(): string
    {
        return __('destination address');
    }

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return DestinationAddressesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDestinationAddresses::route('/'),
        ];
    }
}
