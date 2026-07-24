<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\WorkerDeployer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * End-to-end diagnosis of the inbound-mail chain for a tenant:
 * token -> worker deployed -> catch-all points at that worker -> webhook
 * reachable. Pinpoints the exact broken link behind a "521 Upstream error".
 */
class DoctorCommand extends Command
{
    protected $signature = 'cf:doctor {tenant? : Account slug or id} {--deploy : Also (re)deploy the worker and print the real wrangler result}';

    protected $description = 'Diagnose the inbound-mail chain (token, worker, catch-all, webhook)';

    public function handle(): int
    {
        $accounts = $this->accounts();

        if ($accounts->isEmpty()) {
            $this->error('Tenant bulunamadı.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $this->diagnose($account);
        }

        return self::SUCCESS;
    }

    protected function diagnose(CloudflareAccount $account): void
    {
        $this->newLine();
        $this->line("<fg=cyan;options=bold>== {$account->slug} ({$account->account_id}) ==</>");

        // 1) Token + required permissions ------------------------------------
        $client = null;
        try {
            $client = CloudflareClient::forAccount($account);
            $client->verifyToken();
            $this->good('Token geçerli');
        } catch (Throwable $e) {
            $this->bad('Token doğrulanamadı: '.$e->getMessage());

            return; // nothing else can work
        }

        // 2) Worker deploy state ---------------------------------------------
        $deployer = new WorkerDeployer($account);
        $workerName = $deployer->workerName();

        if (! $account->isWorkerDeployed()) {
            $this->bad("Worker hiç deploy edilmemiş (beklenen ad: {$workerName})");
        } elseif ($deployer->isDrifted()) {
            $this->warnLine("Worker deploy edilmiş ama KAYMIŞ — kod/ayar değişti, yeniden deploy gerekli ({$workerName})");
        } else {
            $this->good("Worker güncel: {$workerName} (".$account->worker_deployed_at->diffForHumans().')');
        }

        // 3) Optional real deploy to surface the true error ------------------
        if ($this->option('deploy')) {
            $this->line('  → deploy deneniyor...');
            try {
                $deployer->deploy();
                $this->good('Deploy başarılı (worker artık Cloudflare’de)');
                $account->refresh();
            } catch (Throwable $e) {
                $this->bad('Deploy başarısız: '.$e->getMessage());
            }
        }

        // 4) Catch-all routing per domain ------------------------------------
        $domains = $account->domains()->whereNotNull('zone_id')->get();
        if ($domains->isEmpty()) {
            $this->warnLine('Zone bağlı domain yok — önce Full Sync yapın.');
        }

        foreach ($domains as $domain) {
            $this->inspectCatchAll($client, $domain, $workerName, $account->isWorkerDeployed());
        }

        // 5) Webhook reachability --------------------------------------------
        $this->pingWebhook($deployer->webhookUrl());
    }

    protected function inspectCatchAll(CloudflareClient $client, Domain $domain, string $workerName, bool $workerDeployed): void
    {
        try {
            $rule = $client->getCatchAllRule($domain->zone_id);
        } catch (Throwable $e) {
            $this->bad("[{$domain->name}] Catch-all okunamadı: ".$e->getMessage()
                .' (token’da Zone · Email Routing Rules · Read izni olmayabilir)');

            return;
        }

        $enabled = (bool) ($rule['enabled'] ?? false);
        $action = $rule['actions'][0]['type'] ?? 'yok';
        $value = $rule['actions'][0]['value'] ?? [];
        $target = is_array($value) ? implode(',', $value) : (string) $value;

        if (! $enabled) {
            $this->bad("[{$domain->name}] Catch-all KAPALI — gelen mail hiçbir yere gitmiyor.");

            return;
        }

        if ($action !== 'worker') {
            $this->warnLine("[{$domain->name}] Catch-all açık ama aksiyon '{$action}' ({$target}) — Worker'a bağlı değil.");

            return;
        }

        if ($target !== $workerName) {
            $this->bad("[{$domain->name}] Catch-all Worker '{$target}'a bakıyor ama beklenen '{$workerName}'. Ad uyuşmuyor.");

            return;
        }

        if (! $workerDeployed) {
            $this->bad("[{$domain->name}] Catch-all doğru Worker'a ('{$workerName}') bakıyor AMA Worker deploy edilmemiş → 521 Upstream error. Çözüm: Worker'ı deploy edin.");

            return;
        }

        $this->good("[{$domain->name}] Catch-all → Worker '{$workerName}' (doğru).");
    }

    protected function pingWebhook(string $url): void
    {
        try {
            // The route only accepts POST; a 405 still proves the app is reachable.
            $res = Http::timeout(10)->get($url);
            $status = $res->status();
            if (in_array($status, [200, 202, 400, 401, 405], true)) {
                $this->good("Webhook URL erişilebilir ({$url} → HTTP {$status})");
            } else {
                $this->warnLine("Webhook URL beklenmedik yanıt verdi: HTTP {$status} ({$url})");
            }
        } catch (Throwable $e) {
            $this->bad("Webhook URL’ye ulaşılamıyor: {$url} — ".$e->getMessage()
                .' (APP_URL yanlış ya da uygulama dışarıdan erişilemiyor olabilir)');
        }
    }

    protected function accounts()
    {
        if ($tenant = $this->argument('tenant')) {
            return CloudflareAccount::where('slug', $tenant)
                ->orWhere('id', is_numeric($tenant) ? $tenant : 0)
                ->get();
        }

        return CloudflareAccount::all();
    }

    protected function good(string $msg): void
    {
        $this->line("  <fg=green>✓</> {$msg}");
    }

    protected function warnLine(string $msg): void
    {
        $this->line("  <fg=yellow>!</> {$msg}");
    }

    protected function bad(string $msg): void
    {
        $this->line("  <fg=red>✗</> {$msg}");
    }
}
