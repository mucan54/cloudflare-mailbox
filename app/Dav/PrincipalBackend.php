<?php

namespace App\Dav;

use App\Models\Mailbox;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * One principal per mailbox: principals/<email>. Backed by the mailboxes table.
 */
class PrincipalBackend implements BackendInterface
{
    private function principalFor(Mailbox $mailbox): array
    {
        return [
            'uri' => 'principals/'.$mailbox->email,
            '{DAV:}displayname' => $mailbox->display_name ?: $mailbox->email,
            '{http://sabredav.org/ns}email-address' => $mailbox->email,
        ];
    }

    public function getPrincipalsByPrefix($prefixPath): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        return Mailbox::query()->get()->map(fn (Mailbox $m) => $this->principalFor($m))->all();
    }

    public function getPrincipalByPath($path): ?array
    {
        if (! str_starts_with($path, 'principals/')) {
            return null;
        }
        $email = substr($path, strlen('principals/'));
        $mailbox = Mailbox::where('email', $email)->first();

        return $mailbox ? $this->principalFor($mailbox) : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch): void {}

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array
    {
        return [];
    }

    public function findByUri($uri, $principalPrefix): ?string
    {
        if (str_starts_with($uri, 'mailto:')) {
            $email = substr($uri, strlen('mailto:'));
            if (Mailbox::where('email', $email)->exists()) {
                return 'principals/'.$email;
            }
        }

        return null;
    }

    public function getGroupMemberSet($principal): array
    {
        return [];
    }

    public function getGroupMembership($principal): array
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members): void {}
}
