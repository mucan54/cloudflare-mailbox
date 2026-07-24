<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Redeploys the inbound Worker for every tenant whose config has drifted.
 * Idempotent — tenants already up-to-date are skipped. Wired into the Coolify
 * post-deploy hook so an APP_URL/secret change reconciles automatically.
 */
class WorkerSyncCommand extends Command
{
    protected $signature = 'cf:worker:sync {--tenant= : Only this account slug/id}';

    protected $description = 'Redeploy inbound Workers whose config drifted (idempotent)';

    public function handle(): int
    {
        $accounts = $this->accounts()->filter(fn (CloudflareAccount $a) => $a->isConnected());

        if ($accounts->isEmpty()) {
            $this->info('Bağlı tenant yok, yapılacak bir şey yok.');

            return self::SUCCESS;
        }

        $deployed = 0;

        foreach ($accounts as $account) {
            $deployer = new WorkerDeployer($account);

            if (! $deployer->isDrifted()) {
                $this->line("• {$account->slug}: güncel, atlandı");

                continue;
            }

            $this->info("• {$account->slug}: kaymış, deploy ediliyor...");

            try {
                $deployer->deploy();
                $deployed++;
                $this->info("  ✓ {$account->slug} deploy edildi");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$account->slug}: ".$e->getMessage());
            }
        }

        $this->info("Bitti. {$deployed} worker güncellendi.");

        return self::SUCCESS;
    }

    protected function accounts(): Collection
    {
        if ($tenant = $this->option('tenant')) {
            return CloudflareAccount::where('slug', $tenant)
                ->orWhere('id', is_numeric($tenant) ? $tenant : 0)
                ->get();
        }

        return CloudflareAccount::all();
    }
}
