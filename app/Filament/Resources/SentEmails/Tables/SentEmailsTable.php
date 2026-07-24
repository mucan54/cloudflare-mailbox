<?php

namespace App\Filament\Resources\SentEmails\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SentEmailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Tarih')
                    ->dateTime()
                    ->since()
                    ->sortable(),

                TextColumn::make('from_email')
                    ->label('Gönderen')
                    ->searchable(),

                TextColumn::make('to')
                    ->label('Alıcı')
                    ->formatStateUsing(fn ($state) => collect($state)->implode(', '))
                    ->limit(40),

                TextColumn::make('subject')
                    ->label('Konu')
                    ->limit(50)
                    ->searchable(),

                TextColumn::make('driver')
                    ->label('Sürücü')
                    ->badge(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'delivered' => 'success',
                        'queued' => 'info',
                        'bounced' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('sent_at', 'desc');
    }
}
