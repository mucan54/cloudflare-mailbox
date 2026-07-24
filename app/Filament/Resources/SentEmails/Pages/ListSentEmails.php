<?php

namespace App\Filament\Resources\SentEmails\Pages;

use App\Filament\Pages\Compose;
use App\Filament\Resources\SentEmails\SentEmailResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSentEmails extends ListRecords
{
    protected static string $resource = SentEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compose')
                ->label('Yeni mail')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn () => Compose::getUrl()),
        ];
    }
}
