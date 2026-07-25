<?php

namespace App\Filament\Resources\SentEmails\Tables;

use App\Models\SentEmail;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

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
                    ->limit(40)
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

                TextColumn::make('error')
                    ->label('Sebep / hata')
                    ->placeholder('—')
                    ->limit(48)
                    ->tooltip(fn (?string $state) => $state)
                    ->toggleable()
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options([
                        'delivered' => 'Teslim edildi',
                        'queued' => 'Kuyrukta (gönderildi)',
                        'bounced' => 'Geri döndü',
                        'failed' => 'Başarısız',
                    ]),
            ])
            ->recordActions([
                Action::make('detay')
                    ->label('Detay')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (SentEmail $record) => $record->subject ?: '(konu yok)')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Kapat')
                    ->modalContent(fn (SentEmail $record): View => view('filament.sent-detail', ['s' => $record])),
            ])
            ->defaultSort('sent_at', 'desc');
    }
}
