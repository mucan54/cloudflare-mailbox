<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(): array
    {
        $user = User::factory()->create();
        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
        ]);
        $account->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $account];
    }

    public function test_domains_page_renders(): void
    {
        [$user, $account] = $this->tenantUser();

        $this->actingAs($user)
            ->get("/admin/{$account->slug}/domains")
            ->assertSuccessful();
    }

    public function test_routing_rules_page_renders(): void
    {
        [$user, $account] = $this->tenantUser();

        $this->actingAs($user)
            ->get("/admin/{$account->slug}/routing-rules")
            ->assertSuccessful();
    }

    public function test_destination_addresses_page_renders(): void
    {
        [$user, $account] = $this->tenantUser();

        $this->actingAs($user)
            ->get("/admin/{$account->slug}/destination-addresses")
            ->assertSuccessful();
    }

    public function test_tenant_scoping_hides_other_tenant_data(): void
    {
        [$user, $account] = $this->tenantUser();

        $other = CloudflareAccount::create(['name' => 'Other', 'account_id' => 'acc2', 'api_token' => 'tok2']);
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'mine.com']);
        $other->domains()->create(['zone_id' => 'z2', 'name' => 'theirs.com']);

        $this->actingAs($user)
            ->get("/admin/{$account->slug}/domains")
            ->assertSuccessful()
            ->assertSee('mine.com')
            ->assertDontSee('theirs.com');
    }
}
