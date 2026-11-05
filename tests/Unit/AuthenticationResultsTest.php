<?php

namespace Tests\Unit;

use App\Support\AuthenticationResults;
use PHPUnit\Framework\TestCase;

class AuthenticationResultsTest extends TestCase
{
    public function test_cloudflare_dmarc_pass_is_trusted(): void
    {
        $this->assertTrue(AuthenticationResults::dmarcPassed(
            [['authentication-results', 'mx.cloudflare.net; spf=pass; dkim=pass; dmarc=pass']],
        ));
        // name => value map, case-insensitive name, comments ignored
        $this->assertTrue(AuthenticationResults::dmarcPassed(
            ['Authentication-Results' => 'mx.cloudflare.net; dmarc=pass (p=reject dis=none) header.from=a.com'],
            'a.com',
        ));
    }

    public function test_anything_else_is_not(): void
    {
        $this->assertFalse(AuthenticationResults::dmarcPassed(null));
        $this->assertFalse(AuthenticationResults::dmarcPassed([['subject', 'x']]));
        $this->assertFalse(AuthenticationResults::dmarcPassed([['authentication-results', 'mx.cloudflare.net; spf=pass; dkim=pass']]));
        $this->assertFalse(AuthenticationResults::dmarcPassed([['authentication-results', 'mx.cloudflare.net; dmarc=none']]));
        $this->assertFalse(AuthenticationResults::dmarcPassed([['authentication-results', 'mx.cloudflare.net.evil.com; dmarc=pass']]));
        $this->assertFalse(AuthenticationResults::dmarcPassed(
            [['authentication-results', 'mx.cloudflare.net; dmarc=pass header.from=b.com']],
            'a.com',
        ));
        // Only the first header counts — a later (sender-supplied) one never does.
        $this->assertFalse(AuthenticationResults::dmarcPassed([
            ['authentication-results', 'mx.cloudflare.net; dmarc=fail'],
            ['authentication-results', 'mx.cloudflare.net; dmarc=pass'],
        ]));
        // Verdicts hidden in comments don't count.
        $this->assertFalse(AuthenticationResults::dmarcPassed(
            [['authentication-results', 'mx.cloudflare.net; spf=pass (x; dmarc=pass) smtp.mailfrom=evil.com; dmarc=fail']],
        ));
    }
}
