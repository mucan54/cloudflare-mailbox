<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditMailbox extends EditRecord
{
    protected static string $resource = MailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Split the stored email back into domain + local part for the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['local_part'] = Str::before((string) ($data['email'] ?? ''), '@');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['email'] = CreateMailbox::composeEmail($data);
        unset($data['local_part']);

        return $data;
    }
}
