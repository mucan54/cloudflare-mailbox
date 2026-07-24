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

        $counts = (new AccountSynchronizer($account))->full();

        $this->assertSame(['domains' => 1, 'addresses' => 1, 'rules' => 1], $counts);
        $this->assertDatabaseHas('domains', ['zone_id' => 'z1', 'name' => 'a.com', 'routing_enabled' => true]);
        $this->assertDatabaseHas('destination_addresses', ['email' => 'me@gmail.com']);
        $this->assertDatabaseHas('routing_rules', ['cf_id' => 'rule1', 'matcher' => 'support@a.com']);
        $this->assertNotNull($account->fresh()->last_synced_at);

        // Run again — no duplicates.
        (new AccountSynchronizer($account))->full();
        $this->assertSame(1, $account->domains()->count());
        $this->assertSame(1, $account->destinationAddresses()->count());
        $this->assertSame(1, $account->routingRules()->count());
    }

    public function test_destination_address_verified_flag(): void
    {
        $this->fakeCloudflare();
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);

        (new AccountSynchronizer($account))->syncDestinationAddresses();

        $this->assertNotNull($account->destinationAddresses()->first()->verified_at);
    }
}
