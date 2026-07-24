<?php

namespace App\Filament\Support;

use App\Models\CloudflareAccount;
use App\Models\Domain;
use App\Services\Cloudflare\CloudflareClient;
use Throwable;

/**
 * Assembles the sender-authentication DNS records (MX / SPF / DKIM) Cloudflare
 * recommends for a domain, plus a suggested DMARC record when one is missing.
 *
 * Missing or misaligned SPF/DKIM/DMARC is the usual reason a strict receiver
 * (Outlook, Gmail) permanently bounces otherwise-accepted outbound mail.
 *
 * @return array{domain: string, error: ?string, records: array<int, array<string, mixed>>, dmarc: ?array<string, string>}
 */
class SendingDnsGuide
{
    public static function build(CloudflareAccount $account, Domain $domain): array
    {
        $records = [];
        $error = null;

        try {
            $records = CloudflareClient::forAccount($account)->emailRoutingDns($domain->zone_id);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $normalized = collect($records)
            ->map(fn ($r) => [
                'type' => strtoupper((string) ($r['type'] ?? '')),
                'name' => $r['name'] ?? $domain->name,
                'value' => (string) ($r['content'] ?? $r['value'] ?? ''),
                'priority' => $r['priority'] ?? null,
            ])
            ->filter(fn ($r) => $r['type'] !== '')
            ->values()
            ->all();

        $hasDmarc = collect($normalized)
            ->contains(fn ($r) => str_contains(strtolower($r['name']), '_dmarc'));

        return [
            'domain' => $domain->name,
            'error' => $error,
            'records' => $normalized,
            'dmarc' => $hasDmarc ? null : [
                'type' => 'TXT',
                'name' => '_dmarc.'.$domain->name,
                'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain->name,
            ],
        ];
    }
}
