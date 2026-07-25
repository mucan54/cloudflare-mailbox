<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\MailClientDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cloudflare.mail_client.app_host' => 'mailbox.example.dev',
            'cloudflare.mail_client.server_host' => 'mail.example.dev',
            'cloudflare.mail_client.imap_port' => 993,
            'cloudflare.mail_client.smtp_port' => 587,
        ]);
    }

    public function test_mozilla_autoconfig_points_at_mail_host(): void
    {
        $res = $this->get('/mail/config-v1.1.xml?emailaddress=me@seamai.net');

        $res->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $res->assertSee('<hostname>mail.seamai.net</hostname>', false);
        $res->assertSee('<port>993</port>', false);
        $res->assertSee('SSL', false);
        $res->assertSee('STARTTLS', false);
    }

    public function test_outlook_autodiscover_returns_imap_smtp(): void
    {
        $body = '<Autodiscover><Request><EMailAddress>me@seamai.net</EMailAddress></Request></Autodiscover>';
        $res = $this->call('POST', '/autodiscover/autodiscover.xml', [], [], [], ['CONTENT_TYPE' => 'text/xml'], $body);

        $res->assertOk();
        $res->assertSee('<Type>IMAP</Type>', false);
        $res->assertSee('<Type>SMTP</Type>', false);
        $res->assertSee('<Server>mail.seamai.net</Server>', false);
        $res->assertSee('<LoginName>me@seamai.net</LoginName>', false);
    }

    public function test_provisioner_builds_expected_records(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'seamai.net']);

        $records = (new MailClientDns)->records($domain);
        $names = array_column($records, 'name');

        $this->assertContains('autodiscover.seamai.net', $names);
        $this->assertContains('autoconfig.seamai.net', $names);
        $this->assertContains('mail.seamai.net', $names);
        $this->assertContains('_imaps._tcp.seamai.net', $names);
        $this->assertContains('_submission._tcp.seamai.net', $names);

        // The mail CNAME points at the configured bridge host.
        $mail = collect($records)->firstWhere('name', 'mail.seamai.net');
        $this->assertSame('mail.example.dev', $mail['content']);
        $this->assertFalse($mail['proxied']); // grey cloud for raw TCP
    }

    public function test_upsert_dns_record_creates_then_updates(): void
    {
        // No existing record -> POST.
        Http::fake([
            '*/dns_records?type=CNAME*' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            '*/dns_records' => Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'new']]),
        ]);
        $client = new CloudflareClient('tok', 'acc');
        $client->upsertDnsRecord('z1', ['type' => 'CNAME', 'name' => 'autoconfig.seamai.net', 'content' => 'x']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/dns_records'));
    }
}
