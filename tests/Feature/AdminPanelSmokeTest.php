<?php

namespace Tests\Feature;

use App\Filament\Widgets\SetupChecklist;
use App\Models\CloudflareAccount;
use App\Models\User;
use App\Services\Cloudflare\WorkerDeployer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_suppressions_page_renders_with_api_rows(): void
    {
        Http::fake([
            '*/email/sending/suppressions' => Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => [['id' => 's1', 'email' => 'bad@example.com', 'reason' => 'spam_complaint']],
            ]),
        ]);

        [$user, $account] = $this->tenantUser();

        $this->actingAs($user)
            ->get("/admin/{$account->slug}/suppressions")
            ->assertSuccessful()
            ->assertSee('bad@example.com');
    }

    public function test_dashboard_renders(): void
    {
        [$user, $account] = $this->tenantUser();

        $this->actingAs($user)->get("/admin/{$account->slug}")->assertSuccessful();
    }

    public function test_setup_checklist_reflects_progress(): void
    {
        [$user, $account] = $this->tenantUser();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($account);

        $widget = new SetupChecklist;

        // Connected (token+account_id) but not synced / no worker / no mailbox → 1/4.
        $this->assertSame(1, $widget->getDoneCount());
        $this->assertFalse($widget->isComplete());

        // Complete all steps → 4/4 and 100%.
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $account->mailboxes()->create(['email' => 'me@a.com', 'password' => 'x']);
        $account->forceFill(['worker_deployed_at' => now(), 'worker_config_hash' => (new WorkerDeployer($account))->configHash()])->save();

        $this->assertTrue((new SetupChecklist)->isComplete());
        $this->assertSame(100, (new SetupChecklist)->getProgress());
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

    public function test_inbox_and_sent_pages_render(): void
    {
        [$user, $account] = $this->tenantUser();
        $account->emails()->create([
            'ingest_key' => 'k1', 'to_email' => 'me@a.com', 'from_email' => 's@x.com',
            'subject' => 'Hi', 'received_at' => now(),
        ]);

        $account->sentEmails()->create([
            'driver' => 'api', 'from_email' => 'me@a.com', 'to' => ['x@y.com'],
            'subject' => 'Outgoing', 'status' => 'bounced', 'error' => 'Recipient blocked (S3150)',
            'cf_response' => ['permanent_bounces' => ['x@y.com']], 'sent_at' => now(),
        ]);

        $this->actingAs($user)->get("/admin/{$account->slug}/emails")->assertSuccessful()->assertSee('Hi');
        $this->actingAs($user)
            ->get("/admin/{$account->slug}/sent-emails")
            ->assertSuccessful()
            ->assertSee('Outgoing')
            ->assertSee('S3150');
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
