<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Tables\DomainsTable;
use App\Models\Domain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Domainler';

    protected static ?string $modelLabel = 'domain';

    protected static string|UnitEnum|null $navigationGroup = 'Cloudflare';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
        ];
    }
}
