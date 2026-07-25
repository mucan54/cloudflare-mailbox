<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Cloudflare\MailClientDns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the mail-client DNS records (autodiscover/autoconfig/mail CNAMEs +
 * SRV) for a domain via the Cloudflare API. Best-effort — a DNS failure is
 * logged, never fatal.
 */
class ProvisionMailClientDns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $domainId) {}

    public function handle(MailClientDns $dns): void
    {
        $domain = Domain::with('account')->find($this->domainId);
        if (! $domain || ! $domain->account?->isConnected()) {
            return;
        }

        try {
            $dns->provision($domain);
        } catch (Throwable $e) {
            Log::warning('Mail-client DNS provisioning failed', [
                'domain' => $domain->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
