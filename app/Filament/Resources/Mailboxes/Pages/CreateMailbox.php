<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMailbox extends CreateRecord
{
    protected static string $resource = MailboxResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure the mailbox is owned by the current tenant (drives domain
        // resolution and tenant scoping).
        $data['cloudflare_account_id'] = Filament::getTenant()->getKey();

        return $data;
    }
}
