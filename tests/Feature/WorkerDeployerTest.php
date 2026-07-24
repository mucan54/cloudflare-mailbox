<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerDeployerTest extends TestCase
{
    use RefreshDatabase;

    private function account(): CloudflareAccount
    {
        return CloudflareAccount::create([
            'name' => 'Acme', 'slug' => 'acme', 'account_id' => 'acc1', 'api_token' => 'tok',
        ]);
    }

    public function test_renders_wrangler_from_stub(): void
    {
        config()->set('app.url', 'https://mail.example.com');
        $deployer = new WorkerDeployer($this->account());

        $toml = $deployer->renderWrangler();

        $this->assertStringContainsString('name = "mailbox-inbound-acme"', $toml);
        $this->assertStringContainsString('account_id = "acc1"', $toml);
        $this->assertStringContainsString('WEBHOOK_URL = "https://mail.example.com/api/cf/incoming"', $toml);
        $this->assertStringNotContainsString('{{', $toml);
    }

    public function test_config_hash_changes_with_app_url(): void
    {
        $account = $this->account();

        config()->set('app.url', 'https://one.example.com');
        $hash1 = (new WorkerDeployer($account))->configHash();

        config()->set('app.url', 'https://two.example.com');
        $hash2 = (new WorkerDeployer($account))->configHash();

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_drift_detection(): void
    {
        $account = $this->account();
        $deployer = new WorkerDeployer($account);

        $this->assertTrue($deployer->isDrifted());

        $account->forceFill(['worker_config_hash' => $deployer->configHash()])->save();

        $this->assertFalse((new WorkerDeployer($account->fresh()))->isDrifted());
    }
}
