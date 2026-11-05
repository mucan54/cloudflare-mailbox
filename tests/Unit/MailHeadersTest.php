<?php

namespace Tests\Unit;

use App\Support\MailHeaders;
use PHPUnit\Framework\TestCase;

class MailHeadersTest extends TestCase
{
    public function test_first_reads_pairs_and_maps(): void
    {
        $pairs = [['from', 'Ada <ada@a.com>'], ['subject', 'Hi'], ['from', 'second']];
        $this->assertSame('Ada <ada@a.com>', MailHeaders::first($pairs, 'From'));
        $this->assertSame('Hi', MailHeaders::first(['Subject' => 'Hi'], 'subject'));
        $this->assertSame('a', MailHeaders::first(['x-list' => ['a', 'b']], 'X-List'));
        $this->assertNull(MailHeaders::first($pairs, 'reply-to'));
        $this->assertNull(MailHeaders::first(null, 'from'));
    }

    public function test_addresses_reads_a_list(): void
    {
        $this->assertSame(
            ['j@x.com', 'b@y.com'],
            MailHeaders::addresses('"Smith, John" <j@x.com>, b@y.com (Bob, Jr)'),
        );
        $this->assertSame([], MailHeaders::addresses(null));
        $this->assertSame([], MailHeaders::addresses('undisclosed-recipients:;'));
    }

    public function test_address_extracts_the_mailbox(): void
    {
        $this->assertSame('ada@a.com', MailHeaders::address('"Ada Lovelace" <ada@a.com>'));
        $this->assertSame('ada@a.com', MailHeaders::address('=?UTF-8?B?QWRh?= <ada@a.com>'));
        $this->assertSame('ada@a.com', MailHeaders::address('"me@evil.com" <ada@a.com>'));
        $this->assertSame('ada@a.com', MailHeaders::address('ada@a.com'));
        $this->assertSame('ada@a.com', MailHeaders::address('ada@a.com (Ada Lovelace)'));
        $this->assertNull(MailHeaders::address('undisclosed-recipients:;'));
        $this->assertNull(MailHeaders::address(''));
        $this->assertNull(MailHeaders::address(null));
    }
}
