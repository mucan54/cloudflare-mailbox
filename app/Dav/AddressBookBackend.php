<?php

namespace App\Dav;

use App\Models\Contact;
use App\Models\Mailbox;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * CardDAV backend mapping a mailbox's single address book to the contacts
 * table. Address book id = mailbox id.
 */
class AddressBookBackend extends AbstractBackend
{
    private function mailboxFromPrincipal(string $principalUri): ?Mailbox
    {
        return Mailbox::where('email', basename($principalUri))->first();
    }

    public function getAddressBooksForUser($principalUri): array
    {
        $mailbox = $this->mailboxFromPrincipal($principalUri);
        if (! $mailbox) {
            return [];
        }

        return [[
            'id' => $mailbox->id,
            'uri' => 'default',
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $mailbox->display_name ? $mailbox->display_name.' — Kişiler' : 'Kişiler',
        ]];
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch): void {}

    public function createAddressBook($principalUri, $url, array $properties): void {}

    public function deleteAddressBook($addressBookId): void {}

    public function getCards($addressBookId): array
    {
        return Contact::where('mailbox_id', $addressBookId)->get()
            ->map(fn (Contact $c) => $this->cardInfo($c, false))
            ->all();
    }

    public function getCard($addressBookId, $cardUri): array|false
    {
        $contact = $this->find($addressBookId, $cardUri);

        return $contact ? $this->cardInfo($contact, true) : false;
    }

    public function createCard($addressBookId, $cardUri, $cardData): ?string
    {
        $contact = new Contact(['mailbox_id' => $addressBookId, 'dav_uri' => $cardUri]);
        $this->apply($contact, $cardData);
        $contact->save();

        return '"'.md5($cardData).'"';
    }

    public function updateCard($addressBookId, $cardUri, $cardData): ?string
    {
        $contact = $this->find($addressBookId, $cardUri);
        if (! $contact) {
            return null;
        }
        $this->apply($contact, $cardData);
        $contact->save();

        return '"'.md5($cardData).'"';
    }

    public function deleteCard($addressBookId, $cardUri): bool
    {
        $contact = $this->find($addressBookId, $cardUri);
        $contact?->delete();

        return (bool) $contact;
    }

    // ---- helpers ----

    private function find(int $addressBookId, string $uri): ?Contact
    {
        $q = Contact::where('mailbox_id', $addressBookId);
        if (preg_match('/contact-(\d+)\.vcf$/', $uri, $m)) {
            return $q->where('id', (int) $m[1])->first() ?? $q->where('dav_uri', $uri)->first();
        }

        return $q->where('dav_uri', $uri)->first();
    }

    private function cardInfo(Contact $c, bool $withData): array
    {
        $vcf = $this->toVcard($c);

        $info = [
            'id' => $c->id,
            'uri' => $c->dav_uri ?: ('contact-'.$c->id.'.vcf'),
            'lastmodified' => $c->updated_at?->getTimestamp(),
            'etag' => '"'.md5($vcf).'"',
            'size' => strlen($vcf),
        ];
        if ($withData) {
            $info['carddata'] = $vcf;
        }

        return $info;
    }

    private function toVcard(Contact $c): string
    {
        $card = new VCard([
            'UID' => 'contact-'.$c->id.'@mailbox',
            'FN' => $c->name,
            'N' => [$c->name, '', '', '', ''],
        ]);
        if ($c->email) {
            $card->add('EMAIL', $c->email);
        }
        if ($c->phone) {
            $card->add('TEL', $c->phone);
        }
        if ($c->company || $c->title) {
            $card->add('ORG', $c->company ?: '');
        }
        if ($c->title) {
            $card->add('TITLE', $c->title);
        }
        if ($c->notes) {
            $card->add('NOTE', $c->notes);
        }

        return $card->serialize();
    }

    private function apply(Contact $contact, string $cardData): void
    {
        $vobj = Reader::read($cardData);

        $contact->name = isset($vobj->FN) ? (string) $vobj->FN : (string) ($vobj->N ?? 'İsimsiz');
        $contact->email = isset($vobj->EMAIL) ? (string) $vobj->EMAIL : null;
        $contact->phone = isset($vobj->TEL) ? (string) $vobj->TEL : null;
        $contact->company = isset($vobj->ORG) ? (string) $vobj->ORG : null;
        $contact->title = isset($vobj->TITLE) ? (string) $vobj->TITLE : null;
        $contact->notes = isset($vobj->NOTE) ? (string) $vobj->NOTE : null;
    }
}
