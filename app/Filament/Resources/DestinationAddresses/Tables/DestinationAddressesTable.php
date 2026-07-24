<?php

namespace App\Filament\Resources\DestinationAddresses\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationAddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Hedef adres')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('verified_at')
                    ->label('Doğrulanmış')
                    ->boolean()
                    ->state(fn ($record) => $record->verified_at !== null),

                TextColumn::make('created_at')
                    ->label('Eklendi')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('email');
    }
}
