<?php

namespace App\Filament\Resources\Emails\Pages;

use App\Filament\Resources\Emails\EmailResource;
use Filament\Resources\Pages\ListRecords;

class ListEmails extends ListRecords
{
    protected static string $resource = EmailResource::class;
}
