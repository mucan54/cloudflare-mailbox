<?php

namespace Tests\Feature;

use App\Filament\Support\SendingDnsGuide;
use App\Models\CloudflareAccount;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendingDnsGuideTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CloudflareAccount, 1: Domain} */
    private function domain(): array
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'seamai.net']);

        return [$account, $domain];
    }

    public function test_returns_api_records_and_suggests_missing_dmarc(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response([
                'success' => true, 'errors' => [], 'result' => [
                    ['type' => 'TXT', 'name' => 'seamai.net', 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all'],
                ],
            ]),
        ]);

        [$account, $domain] = $this->domain();
        $guide = SendingDnsGuide::build($account, $domain);

        $this->assertNull($guide['error']);
        $this->assertCount(1, $guide['records']);
        $this->assertNull($guide['spf'], 'SPF present in API → no suggestion');
        $this->assertNotNull($guide['dmarc'], 'DMARC absent → suggested');
        $this->assertSame('_dmarc.seamai.net', $guide['dmarc']['name']);

        // Auth-status summary: SPF present, DKIM + DMARC missing.
        $this->assertSame(['spf' => true, 'dkim' => false, 'dmarc' => false], $guide['auth_status']);
    }

    public function test_detects_dkim_record_in_auth_status(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response([
                'success' => true, 'errors' => [], 'result' => [
                    ['type' => 'CNAME', 'name' => 'cf2024-1._domainkey.seamai.net', 'content' => 'cf2024-1.dkim.cloudflare.net'],
                    ['type' => 'TXT', 'name' => '_dmarc.seamai.net', 'content' => 'v=DMARC1; p=none'],
                ],
            ]),
        ]);

        [$account, $domain] = $this->domain();
        $guide = SendingDnsGuide::build($account, $domain);

        $this->assertTrue($guide['auth_status']['dkim'], 'DKIM CNAME detected');
        $this->assertTrue($guide['auth_status']['dmarc']);
        $this->assertFalse($guide['auth_status']['spf']);
    }

    public function test_auth_status_is_null_when_records_unavailable(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response(['success' => false, 'errors' => [['message' => 'boom']]], 500),
        ]);

        [$account, $domain] = $this->domain();
        $guide = SendingDnsGuide::build($account, $domain);

        $this->assertNull($guide['auth_status'], 'no false negatives when the fetch failed');
    }

    public function test_auth_error_falls_back_to_spf_and_dmarc_suggestions(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
                'result' => null,
            ], 403),
        ]);

        [$account, $domain] = $this->domain();
        $guide = SendingDnsGuide::build($account, $domain);

        $this->assertNotNull($guide['error']);
        $this->assertTrue($guide['auth_error']);
        $this->assertEmpty($guide['records']);
        $this->assertNotNull($guide['spf']);
        $this->assertStringContainsString('_spf.mx.cloudflare.net', $guide['spf']['value']);
        $this->assertNotNull($guide['dmarc']);
    }
}
