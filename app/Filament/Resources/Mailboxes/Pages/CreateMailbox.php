<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Filament\Resources\Mailboxes\MailboxResource;
use App\Models\Domain;
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
        $data['cloudflare_account_id'] = Filament::getTenant()->getKey();
        $data['email'] = static::composeEmail($data);
        unset($data['local_part']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function composeEmail(array $data): string
    {
        $domain = Domain::find($data['domain_id'] ?? null);
        $local = strtolower(trim((string) ($data['local_part'] ?? '')));

        return $local.'@'.($domain?->name ?? '');
    }
}
