<?php

namespace App\Filament\Resources\Emails\Tables;

use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class EmailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('read_at')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->state(fn ($record) => $record->read_at !== null),

                TextColumn::make('from_email')
                    ->label('Gönderen')
                    ->description(fn ($record) => $record->from_name)
                    ->searchable(),

                TextColumn::make('to_email')
                    ->label('Alıcı')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('subject')
                    ->label('Konu')
                    ->limit(60)
                    ->weight(fn ($record) => $record->read_at ? null : 'bold')
                    ->searchable(),

                TextColumn::make('received_at')
                    ->label('Tarih')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Aç')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn ($record) => $record->subject ?: '(konu yok)')
                    ->modalContent(fn ($record) => new HtmlString(static::renderBody($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Kapat')
                    ->action(fn ($record) => $record->read_at ?: $record->update(['read_at' => now()])),
            ])
            ->defaultSort('received_at', 'desc');
    }

    protected static function renderBody($record): string
    {
        $meta = e($record->from_email).' → '.e($record->to_email).'<br><small>'
            .e(optional($record->received_at)->toDayDateTimeString()).'</small><hr>';

        $body = $record->html_body
            ? '<div class="prose max-w-none">'.$record->html_body.'</div>'
            : '<pre style="white-space:pre-wrap">'.e($record->text_body ?? '').'</pre>';

        return $meta.$body;
    }
}
