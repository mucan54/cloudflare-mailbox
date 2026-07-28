<?php

namespace App\Dav;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Hash;
use Sabre\DAV\Auth\Backend\AbstractBasic;

/**
 * HTTP Basic auth for CalDAV/CardDAV — validates the mailbox email + password
 * (the same credentials as the web/IMAP login). The principal is the email.
 */
class AuthBackend extends AbstractBasic
{
    public function __construct()
    {
        $this->realm = 'Mailbox DAV';
        $this->principalPrefix = 'principals/';
    }

    protected function validateUserPass($username, $password): bool
    {
        $mailbox = Mailbox::where('email', $username)->where('login_enabled', true)->first();

        return $mailbox && Hash::check($password, $mailbox->password);
    }
}
