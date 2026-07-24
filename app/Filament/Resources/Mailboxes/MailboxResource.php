<?php

namespace App\Filament\Resources\Mailboxes;

use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Filament\Resources\Mailboxes\Pages\EditMailbox;
use App\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use App\Filament\Resources\Mailboxes\Schemas\MailboxForm;
use App\Filament\Resources\Mailboxes\Tables\MailboxesTable;
use App\Models\Mailbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MailboxResource extends Resource
{
    protected static ?string $model = Mailbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Mail';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Mailboxes');
    }

    public static function getModelLabel(): string
    {
        return __('mailbox');
    }

    public static function form(Schema $schema): Schema
    {
        return MailboxForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailboxesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxes::route('/'),
            'create' => CreateMailbox::route('/create'),
            'edit' => EditMailbox::route('/{record}/edit'),
        ];
    }
}
