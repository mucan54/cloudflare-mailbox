<?php

namespace App\Filament\Resources\Mailboxes\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailboxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label(__('Mailbox'))
                    ->description(fn ($record) => $record->display_name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain.name')
                    ->label(__('Domain'))
                    ->toggleable(),

                IconColumn::make('login_enabled')
                    ->label(__('Login'))
                    ->boolean(),

                TextColumn::make('emails_count')
                    ->label(__('Inbox'))
                    ->counts('emails')
                    ->badge(),

                TextColumn::make('last_login_at')
                    ->label(__('Last login'))
                    ->since()
                    ->placeholder(__('never'))
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('email');
    }
}
