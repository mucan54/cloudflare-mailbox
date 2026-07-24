<?php

namespace App\Filament\Resources\Mailboxes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MailboxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label(__('Email address'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText(__('Must be an address on one of your domains. This is the login for the mailbox app.')),

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
