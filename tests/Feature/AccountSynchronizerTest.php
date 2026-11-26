<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\AccountSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCloudflare(): void
    {
        Http::fake([
            '*/zones/z1/email/sending/subdomains*' => Http::response($this->ok([
                ['id' => 'sub1', 'name' => 'a.com', 'enabled' => true],
            ])),
            '*/zones/z1/email/routing/rules*' => Http::response($this->ok([
                [
                    'tag' => 'rule1',
                    'name' => 'support',
                    'enabled' => true,
                    'priority' => 0,
                    'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => 'support@a.com']],
                    'actions' => [['type' => 'forward', 'value' => ['me@gmail.com']]],
                ],
            ])),
            '*/zones/z1/email/routing*' => Http::response($this->ok(['enabled' => true, 'status' => 'ready'])),
            '*/zones*' => Http::response($this->ok(
                [['id' => 'z1', 'name' => 'a.com', 'status' => 'active']],
                ['result_info' => ['page' => 1, 'total_pages' => 1]],
            )),
            '*/email/routing/addresses*' => Http::response($this->ok([
                ['id' => 'addr1', 'email' => 'me@gmail.com', 'verified' => '2026-01-01T00:00:00Z'],
            ])),
        ]);
    }

    private function ok(array $result, array $extra = []): array
    {
        return array_merge(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result], $extra);
    }

    public function test_full_sync_populates_and_is_idempotent(): void
    {
        $this->fakeCloudflare();

        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
        ]);

        $result = (new AccountSynchronizer($account))->full();

        $this->assertSame(['domains' => 1, 'addresses' => 1, 'rules' => 1], $result['counts']);
        $this->assertEmpty($result['errors']);
        $this->assertDatabaseHas('domains', [
            'zone_id' => 'z1',
            'name' => 'a.com',
            'routing_enabled' => true,
            'sending_enabled' => true,
        ]);
        $this->assertDatabaseHas('destination_addresses', ['email' => 'me@gmail.com']);
        $this->assertDatabaseHas('routing_rules', ['cf_id' => 'rule1', 'matcher' => 'support@a.com']);
        $this->assertNotNull($account->fresh()->last_synced_at);

        // Run again — no duplicates.
        (new AccountSynchronizer($account))->full();
        $this->assertSame(1, $account->domains()->count());
        $this->assertSame(1, $account->destinationAddresses()->count());
        $this->assertSame(1, $account->routingRules()->count());
    }

    public function test_full_sync_is_resilient_to_missing_permission(): void
    {
        // Zones/routing OK, but the addresses endpoint returns an auth error
        // (token lacks Email Routing permission).
        Http::fake([
            '*/email/routing/addresses*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
                'result' => null,
            ], 403),
            '*/zones/z1/email/routing/rules*' => Http::response($this->ok([])),
            '*/zones/z1/email/routing*' => Http::response($this->ok(['enabled' => true])),
            '*/zones*' => Http::response($this->ok(
                [['id' => 'z1', 'name' => 'a.com', 'status' => 'active']],
                ['result_info' => ['page' => 1, 'total_pages' => 1]],
            )),
        ]);

        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);

        $result = (new AccountSynchronizer($account))->full();

        $this->assertSame(1, $result['counts']['domains']);            // domains still synced
        $this->assertArrayHasKey('addresses', $result['errors']);      // addresses reported failed
        $this->assertDatabaseHas('domains', ['zone_id' => 'z1']);
    }

    public function test_sync_reads_sending_and_routing_when_settings_endpoint_is_forbidden(): void
    {
        // The settings endpoint 403s on this token, but rules and sending
        // subdomains are readable. Full Sync must still mark both columns on.
        Http::fake([
            '*/zones/z1/email/sending/subdomains*' => Http::response($this->ok([
                ['id' => 'sub1', 'name' => 'a.com', 'enabled' => true],
            ])),
            '*/zones/z1/email/routing/rules*' => Http::response($this->ok([
                [
                    'tag' => 'catchall',
                    'name' => 'catch-all -> worker',
                    'enabled' => true,
                    'priority' => 2147483647,
                    'matchers' => [['type' => 'all']],
                    'actions' => [['type' => 'worker', 'value' => ['mailbox-inbound']]],
                ],
            ])),
            '*/zones/z1/email/routing*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
                'result' => null,
            ], 403),
            '*/zones*' => Http::response($this->ok(
                [['id' => 'z1', 'name' => 'a.com', 'status' => 'active']],
                ['result_info' => ['page' => 1, 'total_pages' => 1]],
            )),
            '*/email/routing/addresses*' => Http::response($this->ok([])),
        ]);

        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);

        $result = (new AccountSynchronizer($account))->full();

        $this->assertSame(1, $result['counts']['domains']);
        $this->assertDatabaseHas('domains', [
            'zone_id' => 'z1',
            'status' => 'active',
            'routing_enabled' => true,
            'sending_enabled' => true,
        ]);
    }

    public function test_sync_keeps_sending_flag_when_sending_api_is_forbidden(): void
    {
        Http::fake([
            '*/zones/z1/email/sending/subdomains*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
                'result' => null,
            ], 403),
            '*/zones/z1/email/routing/rules*' => Http::response($this->ok([])),
            '*/zones/z1/email/routing*' => Http::response($this->ok(['enabled' => false, 'status' => 'disabled'])),
            '*/zones*' => Http::response($this->ok(
                [['id' => 'z1', 'name' => 'a.com', 'status' => 'active']],
                ['result_info' => ['page' => 1, 'total_pages' => 1]],
            )),
        ]);

        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $account->domains()->create([
            'zone_id' => 'z1',
            'name' => 'a.com',
            'sending_enabled' => true,
            'routing_enabled' => true,
        ]);

        (new AccountSynchronizer($account))->syncDomains();

        $domain = $account->domains()->first();
        $this->assertTrue($domain->sending_enabled);
        $this->assertFalse($domain->routing_enabled);
    }

    public function test_destination_address_verified_flag(): void
    {
        $this->fakeCloudflare();
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);

        (new AccountSynchronizer($account))->syncDestinationAddresses();

        $this->assertNotNull($account->destinationAddresses()->first()->verified_at);
    }
}
