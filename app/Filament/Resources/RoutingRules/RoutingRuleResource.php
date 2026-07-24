<?php

namespace App\Filament\Resources\RoutingRules;

use App\Filament\Resources\RoutingRules\Pages\ListRoutingRules;
use App\Filament\Resources\RoutingRules\Tables\RoutingRulesTable;
use App\Models\RoutingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RoutingRuleResource extends Resource
{
    protected static ?string $model = RoutingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Cloudflare';

    public static function getNavigationLabel(): string
    {
        return __('Mail addresses (rules)');
    }

    public static function getModelLabel(): string
    {
        return __('routing rule');
    }

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return RoutingRulesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoutingRules::route('/'),
        ];
    }
}
