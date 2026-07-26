<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DavServerTest extends TestCase
{
    use RefreshDatabase;

    // The DAV server + its routes are opt-in; MAIL_CLIENT_DAV=true is set in
    // phpunit.xml so the routes register during the test app's boot.

    private function mailbox(): Mailbox
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        return $account->mailboxes()->create([
            'domain_id' => $domain->id, 'email' => 'me@a.com', 'password' => 'password123', 'login_enabled' => true,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('me@a.com:password123')];
    }

    public function test_dav_requires_auth(): void
    {
        $this->call('PROPFIND', '/dav/')->assertStatus(401);
    }

    public function test_wellknown_redirects(): void
    {
        $this->get('/.well-known/caldav')->assertRedirect('/dav/');
        $this->get('/.well-known/carddav')->assertRedirect('/dav/');
    }

    public function test_propfind_root_returns_multistatus(): void
    {
        $this->mailbox();
        $res = $this->call('PROPFIND', '/dav/', [], [], [], $this->serverHeaders(['Depth' => '0']));
        $res->assertStatus(207);
    }

    public function test_put_ics_creates_event_and_get_returns_it(): void
    {
        $mailbox = $this->mailbox();

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\nSUMMARY:Standup\r\nDTSTART:20260810T090000Z\r\nDTEND:20260810T093000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $put = $this->call('PUT', '/dav/calendars/me@a.com/default/evt-1.ics', [], [], [], $this->serverHeaders([], 'text/calendar'), $ics);
        $this->assertContains($put->getStatusCode(), [201, 204]);

        $this->assertDatabaseHas('events', ['mailbox_id' => $mailbox->id, 'title' => 'Standup']);

        $get = $this->call('GET', '/dav/calendars/me@a.com/default/evt-1.ics', [], [], [], $this->serverHeaders());
        $get->assertOk();
        $this->assertStringContainsString('SUMMARY:Standup', $get->getContent());
    }

    public function test_put_vcard_creates_contact(): void
    {
        $mailbox = $this->mailbox();

        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-1\r\nFN:Jane Doe\r\nEMAIL:jane@x.com\r\nTEL:+123\r\nEND:VCARD\r\n";

        $put = $this->call('PUT', '/dav/addressbooks/me@a.com/default/c-1.vcf', [], [], [], $this->serverHeaders([], 'text/vcard'), $vcf);
        $this->assertContains($put->getStatusCode(), [201, 204]);

        $this->assertDatabaseHas('contacts', ['mailbox_id' => $mailbox->id, 'name' => 'Jane Doe', 'email' => 'jane@x.com']);
    }

    public function test_mobileconfig_profile_bundles_caldav_and_carddav(): void
    {
        $this->mailbox();

        $res = $this->get('/mail/profile/me@a.com.mobileconfig');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/x-apple-aspen-config; charset=utf-8');
        $body = $res->getContent();
        $this->assertStringContainsString('com.apple.caldav.account', $body);
        $this->assertStringContainsString('com.apple.carddav.account', $body);
        $this->assertStringContainsString('/dav/principals/me@a.com/', $body);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function serverHeaders(array $extra = [], ?string $contentType = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('me@a.com:password123'),
        ];
        foreach ($extra as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        if ($contentType) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        return $server;
    }
}
