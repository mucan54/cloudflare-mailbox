<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\RoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoutingManagerTest extends TestCase
{
    use RefreshDatabase;

    private function ok(array $result): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    }

    private function account(): CloudflareAccount
    {
        return CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
    }

    public function test_add_destination_address(): void
    {
        Http::fake(['*/email/routing/addresses*' => Http::response($this->ok(['id' => 'addr1', 'email' => 'me@gmail.com']))]);
        $account = $this->account();

        $addr = (new RoutingManager($account))->addDestinationAddress('me@gmail.com');

        $this->assertSame('addr1', $addr->cf_id);
        $this->assertDatabaseHas('destination_addresses', ['email' => 'me@gmail.com', 'cf_id' => 'addr1']);
    }

    public function test_create_forward_rule(): void
    {
        Http::fake(['*/email/routing/rules*' => Http::response($this->ok([
            'tag' => 'rule1',
            'actions' => [['type' => 'forward', 'value' => ['me@gmail.com']]],
        ]))]);
        $account = $this->account();
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        $rule = (new RoutingManager($account))->createForwardRule($domain, 'support@a.com', ['me@gmail.com']);

        $this->assertSame('rule1', $rule->cf_id);
        $this->assertSame('support@a.com', $rule->matcher);
        $this->assertDatabaseHas('routing_rules', ['cf_id' => 'rule1', 'domain_id' => $domain->id]);
    }

    public function test_set_catch_all_to_worker(): void
    {
        Http::fake(['*/email/routing/rules/catch_all*' => Http::response($this->ok(['enabled' => true]))]);
        $account = $this->account();
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        (new RoutingManager($account))->setCatchAllToWorker($domain, 'mailbox-inbound-acme');

        $this->assertSame('catch_all', $domain->fresh()->inbound_capture);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/catch_all')
            && $r['actions'][0]['type'] === 'worker');
    }

    public function test_delete_rule(): void
    {
        Http::fake(['*' => Http::response($this->ok(['id' => 'rule1']))]);
        $account = $this->account();
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $rule = $account->routingRules()->create([
            'domain_id' => $domain->id, 'cf_id' => 'rule1', 'matcher' => 'x@a.com', 'actions' => [],
        ]);

        (new RoutingManager($account))->deleteRule($rule);

        $this->assertDatabaseMissing('routing_rules', ['id' => $rule->id]);
    }
}
