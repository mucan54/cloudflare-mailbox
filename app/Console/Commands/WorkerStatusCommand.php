<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Console\Command;

class WorkerStatusCommand extends Command
{
    protected $signature = 'cf:worker:status';

    protected $description = 'Show inbound Worker deploy status per tenant (up-to-date / drifted / never)';

    public function handle(): int
    {
        $rows = CloudflareAccount::all()->map(function (CloudflareAccount $account) {
            $status = match (true) {
                ! $account->isWorkerDeployed() => 'hiç deploy edilmemiş',
                (new WorkerDeployer($account))->isDrifted() => 'KAYMIŞ',
                default => 'güncel',
            };

            return [
                $account->slug,
                $account->account_id ?: '—',
                $account->worker_deployed_at?->diffForHumans() ?? '—',
                $status,
            ];
        });

        $this->table(['Tenant', 'Account ID', 'Son deploy', 'Durum'], $rows);

        return self::SUCCESS;
    }
}
