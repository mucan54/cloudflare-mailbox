<?php

namespace App\Console\Commands;

use App\Models\CloudflareAccount;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\WebhookSignature;
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
    protected $signature = 'cf:doctor {tenant? : Account slug or id} {--deploy : Also (re)deploy the worker and print the real wrangler result} {--webhook-test : POST a signed test payload to the webhook to verify the secret end-to-end (creates one throwaway email row)}';

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
            $this->inspectExplicitRules($client, $domain, $workerName);
            $this->inspectCatchAll($client, $domain, $workerName, $account->isWorkerDeployed());
        }

        // 5) Webhook reachability --------------------------------------------
        $this->pingWebhook($deployer->webhookUrl());

        // 6) Signed webhook self-test (opt-in) -------------------------------
        if ($this->option('webhook-test')) {
            $this->webhookSelfTest($account, $deployer->webhookUrl());
        }
    }

    /**
     * POST a properly-signed payload to the webhook exactly as the Worker would.
     * 202 proves the webhook + account lookup + secret + signature all work, so
     * a "no email stored" symptom is a Worker-can't-reach-Laravel or queue issue.
     * 401 proves the Worker's WEBHOOK_SECRET no longer matches — redeploy.
     */
    protected function webhookSelfTest(CloudflareAccount $account, string $url): void
    {
        $body = json_encode([
            'message_id' => '<cf-doctor-'.$account->id.'@selftest.local>',
            'envelope_to' => 'cf-doctor@selftest.local',
            'envelope_from' => 'doctor@selftest.local',
            'subject' => '[cf:doctor] webhook self-test',
            'text' => 'Bu satır cf:doctor --webhook-test tarafından üretildi; silebilirsiniz.',
        ]);
        $ts = (string) (time() * 1000);
        $signature = WebhookSignature::sign((string) $account->webhook_secret, $ts, $body);

        try {
            $res = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-CF-Account' => (string) $account->account_id,
                'X-CF-Signature' => $signature,
                'X-CF-Timestamp' => $ts,
            ])->withBody($body, 'application/json')->post($url);

            if ($res->status() === 202) {
                $this->good('İmzalı webhook self-test: 202 (webhook + secret + imza doğru). '
                    .'Mail saklanmıyorsa sebep Worker→Laravel erişimi ya da queue worker’ıdır.');
            } elseif ($res->status() === 401) {
                $this->bad('İmzalı webhook self-test: 401 — Worker’ın WEBHOOK_SECRET’i saklanan '
                    .'webhook_secret ile UYUŞMUYOR. Çözüm: `php artisan cf:doctor '.$account->slug.' --deploy` ile yeniden deploy edin.');
            } else {
                $this->warnLine('İmzalı webhook self-test beklenmedik yanıt: HTTP '.$res->status().' — '.$res->body());
            }
        } catch (Throwable $e) {
            $this->bad('İmzalı webhook self-test gönderilemedi: '.$e->getMessage());
        }
    }

    /**
     * Explicit per-address rules ALWAYS take precedence over the catch-all in
     * Cloudflare Email Routing. A stray literal rule for an address silently
     * overrides catch-all → Worker, so that address never reaches the Worker.
     */
    protected function inspectExplicitRules(CloudflareClient $client, Domain $domain, string $workerName): void
    {
        try {
            $rules = $client->listRoutingRules($domain->zone_id);
        } catch (Throwable $e) {
            return; // catch-all inspection reports the read-permission issue
        }

        foreach ($rules as $r) {
            if (! ($r['enabled'] ?? false)) {
                continue;
            }

            $literal = collect($r['matchers'] ?? [])->firstWhere('type', 'literal');
            if (! $literal) {
                continue; // 'all' matcher is the catch-all, handled separately
            }

            $addr = $literal['value'] ?? '?';
            $action = $r['actions'][0]['type'] ?? 'yok';
            $value = $r['actions'][0]['value'] ?? [];
            $target = is_array($value) ? implode(',', $value) : (string) $value;

            if ($action === 'worker' && $target === $workerName) {
                $this->good("[{$domain->name}] Özel kural {$addr} → Worker (doğru).");
            } else {
                $this->warnLine("[{$domain->name}] Özel kural {$addr} → {$action} ({$target}) — bu kural "
                    .'catch-all’ı EZER, yani bu adres Worker’a gitmez. Worker istiyorsanız bu kuralı silin '
                    .'ya da Worker’a yönlendirin.');
            }
        }
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
