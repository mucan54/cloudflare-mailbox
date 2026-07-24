<?php

namespace App\Filament\Resources\SentEmails;

use App\Filament\Resources\SentEmails\Pages\ListSentEmails;
use App\Filament\Resources\SentEmails\Tables\SentEmailsTable;
use App\Models\SentEmail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SentEmailResource extends Resource
{
    protected static ?string $model = SentEmail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'Giden';

    protected static ?string $modelLabel = 'giden mail';

    protected static string|UnitEnum|null $navigationGroup = 'Mail';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return SentEmailsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSentEmails::route('/'),
        ];
    }
}
