<?php

namespace App\Filament\Resources\Domains\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Zone')
                    ->badge()
                    ->sortable(),

                IconColumn::make('sending_enabled')
                    ->label('Gönderme')
                    ->boolean(),

                IconColumn::make('routing_enabled')
                    ->label('Alma')
                    ->boolean(),

                TextColumn::make('inbound_capture')
                    ->label('Gelen yakalama')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'catch_all' => 'Catch-all',
                        'per_address' => 'Adres bazlı',
                        default => 'Kapalı',
                    })
                    ->color(fn (string $state) => $state === 'none' ? 'gray' : 'success'),

                TextColumn::make('last_synced_at')
                    ->label('Son senkron')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}
