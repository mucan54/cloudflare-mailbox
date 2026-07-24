<?php

namespace App\Filament\Resources\RoutingRules\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoutingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain.name')
                    ->label('Domain')
                    ->sortable(),

                TextColumn::make('matcher')
                    ->label('Adres / eşleşme')
                    ->description(fn ($record) => $record->is_catch_all ? 'catch-all' : null)
                    ->searchable(),

                TextColumn::make('actions')
                    ->label('Aksiyon')
                    ->formatStateUsing(function ($state) {
                        $actions = collect(is_array($state) ? $state : [])
                            ->map(fn ($a) => $a['type'] ?? '?');

                        return $actions->isNotEmpty() ? $actions->implode(', ') : '—';
                    }),

                IconColumn::make('enabled')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                //
            ]);
    }
}
