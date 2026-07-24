<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareAccount;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Renders the inbound Worker's wrangler.toml from Laravel config and deploys it
 * with wrangler. Laravel is the single source of truth; a config hash drives
 * drift detection (see cf:worker:sync).
 */
class WorkerDeployer
{
    public function __construct(protected CloudflareAccount $account) {}

    public function workerName(): string
    {
        return config('cloudflare.worker.name_prefix', 'mailbox-inbound').'-'.$this->account->slug;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/cf/incoming';
    }

    /**
     * A stable hash of everything that affects the deployed Worker. When this
     * changes, the Worker is drifted and must be redeployed.
     */
    public function configHash(): string
    {
        return hash('sha256', implode('|', [
            $this->webhookUrl(),
            (string) $this->account->account_id,
            (string) $this->account->webhook_secret,
            (string) config('cloudflare.worker.fallback_forward_to'),
            (string) config('cloudflare.webhook.inline_attachment_max_bytes'),
            $this->scriptVersion(),
        ]));
    }

    public function isDrifted(): bool
    {
        return $this->account->worker_config_hash !== $this->configHash();
    }

    public function renderWrangler(): string
    {
        $stubPath = config('cloudflare.worker.directory').'/wrangler.toml.stub';

        if (! is_file($stubPath)) {
            throw new RuntimeException("wrangler.toml.stub not found at {$stubPath}");
        }

        return strtr(file_get_contents($stubPath), [
            '{{WORKER_NAME}}' => $this->workerName(),
            '{{ACCOUNT_ID}}' => (string) $this->account->account_id,
            '{{WEBHOOK_URL}}' => $this->webhookUrl(),
            '{{INLINE_ATTACHMENT_MAX}}' => (string) config('cloudflare.webhook.inline_attachment_max_bytes'),
            '{{FALLBACK_FORWARD_TO}}' => (string) config('cloudflare.worker.fallback_forward_to'),
        ]);
    }

    /**
     * Render + deploy + push the webhook secret. Returns the wrangler output.
     * Requires node/wrangler on the host and the tenant's API token.
     */
    public function deploy(): string
    {
        if (! $this->account->isConnected()) {
            throw new RuntimeException('Cloudflare hesabı bağlı değil.');
        }

        $dir = (string) config('cloudflare.worker.directory');
        file_put_contents($dir.'/wrangler.toml', $this->renderWrangler());

        $env = ['CLOUDFLARE_API_TOKEN' => $this->account->api_token];
        $bin = (string) config('cloudflare.worker.wrangler_bin', 'npx wrangler');

        // Ensure the Worker's dependencies (postal-mime) are installed so wrangler
        // can bundle the script.
        if (! is_dir($dir.'/node_modules/postal-mime')) {
            $install = Process::path($dir)->timeout(300)->run('npm install --no-audit --no-fund');
            if (! $install->successful()) {
                throw new RuntimeException('cf/ bağımlılıkları kurulamadı (npm install): '
                    .trim($install->errorOutput() ?: $install->output()));
            }
        }

        $deploy = Process::path($dir)->env($env)->timeout(300)->run("{$bin} deploy");

        if (! $deploy->successful()) {
            throw new RuntimeException($this->explainFailure(trim($deploy->errorOutput()."\n".$deploy->output())));
        }

        // Push the per-account webhook secret (stdin, never on the CLI).
        Process::path($dir)->env($env)->timeout(120)
            ->input($this->account->webhook_secret)
            ->run("{$bin} secret put WEBHOOK_SECRET");

        $this->account->forceFill([
            'worker_deployed_at' => now(),
            'worker_config_hash' => $this->configHash(),
        ])->save();

        return $deploy->output();
    }

    /**
     * Turn raw wrangler output into an actionable message.
     */
    protected function explainFailure(string $output): string
    {
        $hint = match (true) {
            str_contains($output, 'Could not resolve') || str_contains($output, 'Build failed') => 'cf/ bağımlılıkları eksik görünüyor (postal-mime). Sunucuda `cd cf && npm install` çalıştırın veya image’ı yeniden kurun.',
            str_contains($output, 'Authentication error') || str_contains($output, '10000') || str_contains($output, 'Unauthorized') => 'Token’ınızda “Workers Scripts: Edit” izni yok. Ayarlar’dan token’a bu izni ekleyin.',
            str_contains($output, 'not found') && str_contains(strtolower($output), 'wrangler') => 'wrangler bulunamadı. Sunucuda Node.js + wrangler kurulu olmalı (Docker image bunu içerir).',
            str_contains($output, 'command not found') || str_contains($output, 'npx: not found') => 'Node.js/npx sunucuda kurulu değil.',
            default => null,
        };

        return 'wrangler deploy başarısız: '.$output.($hint ? "\n\n→ ".$hint : '');
    }

    protected function scriptVersion(): string
    {
        $script = config('cloudflare.worker.directory').'/src/inbound-email.js';

        return is_file($script) ? substr(hash('sha256', (string) file_get_contents($script)), 0, 12) : 'na';
    }
}
