<?php

namespace App\Filament\Resources\Mailboxes\Schemas;

use App\Models\Domain;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MailboxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('domain_id')
                ->label(__('Domain'))
                ->options(fn () => Filament::getTenant()->domains()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->native(false)
                ->searchable()
                ->live()
                ->helperText(__('Pick one of your domains.')),

            TextInput::make('local_part')
                ->label(__('Address'))
                ->placeholder('info')
                ->required()
                ->prefixIcon('heroicon-o-at-symbol')
                ->suffix(fn (Get $get): string => '@'.(Domain::find($get('domain_id'))?->name ?? 'domain'))
                ->helperText(__('The part before @. This becomes the login for the mailbox app.')),

            TextInput::make('display_name')
                ->label(__('Display name'))
                ->maxLength(255),

            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(__('Set on create; leave blank when editing to keep the current password.')),

            Toggle::make('login_enabled')
                ->label(__('Login enabled'))
                ->default(true),
        ]);
    }
}
