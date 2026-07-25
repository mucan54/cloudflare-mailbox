<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function ok(array $result): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    }

    public function test_flags_catch_all_pointing_at_undeployed_worker(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $workerName = 'mailbox-inbound-'.$account->slug;

        Http::fake([
            '*/user/tokens/verify' => Http::response($this->ok(['status' => 'active'])),
            '*/email/routing/rules/catch_all' => Http::response($this->ok([
                'enabled' => true,
                'actions' => [['type' => 'worker', 'value' => [$workerName]]],
            ])),
            '*/email/routing/rules*' => Http::response($this->ok([])),
            '*api/cf/incoming' => Http::response('', 405),
        ]);

        $this->artisan('cf:doctor', ['tenant' => $account->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('521 Upstream error');
    }

    public function test_webhook_self_test_reports_success_on_202(): void
    {
        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
            'worker_deployed_at' => now(),
        ]);
        $account->forceFill(['worker_config_hash' => (new WorkerDeployer($account))->configHash()])->save();
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $workerName = 'mailbox-inbound-'.$account->slug;

        Http::fake([
            '*/user/tokens/verify' => Http::response($this->ok(['status' => 'active'])),
            '*/email/routing/rules/catch_all' => Http::response($this->ok([
                'enabled' => true,
                'actions' => [['type' => 'worker', 'value' => [$workerName]]],
            ])),
            '*/email/routing/rules*' => Http::response($this->ok([])),
            '*api/cf/incoming' => Http::response('', 202),
        ]);

        $this->artisan('cf:doctor', ['tenant' => $account->slug, '--webhook-test' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('webhook self-test: 202');
    }

    public function test_reports_healthy_chain_when_worker_deployed(): void
    {
        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
            'worker_deployed_at' => now(),
        ]);
        // Mark the worker config as current so it is not reported as drifted.
        $account->forceFill(['worker_config_hash' => (new WorkerDeployer($account))->configHash()])->save();
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $workerName = 'mailbox-inbound-'.$account->slug;

        Http::fake([
            '*/user/tokens/verify' => Http::response($this->ok(['status' => 'active'])),
            '*/email/routing/rules/catch_all' => Http::response($this->ok([
                'enabled' => true,
                'actions' => [['type' => 'worker', 'value' => [$workerName]]],
            ])),
            '*/email/routing/rules*' => Http::response($this->ok([])),
            '*api/cf/incoming' => Http::response('', 405),
        ]);

        $this->artisan('cf:doctor', ['tenant' => $account->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('doğru');
    }
}
