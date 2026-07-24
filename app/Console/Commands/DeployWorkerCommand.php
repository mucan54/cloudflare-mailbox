<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Console\Command;

class DeployWorkerCommand extends Command
{
    protected $signature = 'cf:deploy-worker {tenant : Account slug or id}';

    protected $description = 'Render wrangler.toml from config and deploy the inbound Email Worker for a tenant';

    public function handle(): int
    {
        $account = $this->resolve($this->argument('tenant'));

        if (! $account) {
            $this->error('Tenant bulunamadı.');

            return self::FAILURE;
        }

        $this->info("Deploying worker for [{$account->slug}]...");

        try {
            $output = (new WorkerDeployer($account))->deploy();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Node + wrangler kurulu ve ağ erişimi olduğundan emin olun (bkz. cf/README.md).');

            return self::FAILURE;
        }

        $this->line($output);
        $this->info('Deploy tamamlandı.');

        return self::SUCCESS;
    }

    protected function resolve(string $key): ?CloudflareAccount
    {
        return CloudflareAccount::where('slug', $key)
            ->orWhere('id', is_numeric($key) ? $key : 0)
            ->first();
    }
}
