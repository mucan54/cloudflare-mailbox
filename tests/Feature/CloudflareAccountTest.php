<?php

namespace Tests\Feature;

use App\Filament\Pages\Tenancy\RegisterCloudflareAccount;
use App\Models\CloudflareAccount;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudflareAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_and_webhook_secret_are_generated(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme Corp']);

        $this->assertSame('acme-corp', $account->slug);
        $this->assertNotEmpty($account->webhook_secret);
        $this->assertGreaterThanOrEqual(40, strlen($account->webhook_secret));
    }

    public function test_slug_is_unique(): void
    {
        $a = CloudflareAccount::create(['name' => 'Acme']);
        $b = CloudflareAccount::create(['name' => 'Acme']);

        $this->assertSame('acme', $a->slug);
        $this->assertSame('acme-1', $b->slug);
    }

    public function test_token_and_secret_are_encrypted_at_rest(): void
    {
        $account = CloudflareAccount::create([
            'name' => 'Acme',
            'api_token' => 'super-secret-token',
        ]);

        $raw = \DB::table('cloudflare_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('super-secret-token', $raw->api_token);
        $this->assertSame('super-secret-token', $account->fresh()->api_token);
    }

    public function test_onboarding_state_machine(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme']);

        $this->assertFalse($account->isConnected());
        $this->assertFalse($account->isSynced());
        $this->assertFalse($account->isWorkerDeployed());

        $account->update(['account_id' => 'acc1', 'api_token' => 'tok']);
        $this->assertTrue($account->fresh()->isConnected());
    }

    public function test_user_tenant_membership(): void
    {
        $user = User::factory()->create();
        $account = CloudflareAccount::create(['name' => 'Acme']);
        $account->users()->attach($user->id, ['role' => 'owner']);

        $this->assertTrue($user->fresh()->canAccessTenant($account));
        $this->assertCount(1, $user->fresh()->getTenants(app(Panel::class)));
    }

    public function test_token_template_url_contains_permission_groups(): void
    {
        $url = RegisterCloudflareAccount::tokenTemplateUrl();

        $this->assertStringContainsString('dash.cloudflare.com', $url);
        $this->assertStringContainsString('permissionGroupKeys=', $url);
        $this->assertStringContainsString('accountId=*', $url);
    }
}
