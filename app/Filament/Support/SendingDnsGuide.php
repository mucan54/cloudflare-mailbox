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

        $hasSpf = collect($normalized)
            ->contains(fn ($r) => $r['type'] === 'TXT' && str_contains(strtolower($r['value']), 'v=spf1'));

        // DKIM: Cloudflare returns the DKIM record(s) as CNAME/TXT under a
        // *._domainkey.<domain> name. Its presence is what stops receivers like
        // Outlook flagging the sender as "unverified".
        $hasDkim = collect($normalized)
            ->contains(fn ($r) => str_contains(strtolower($r['name']), '_domainkey')
                || str_contains(strtolower($r['value']), 'v=dkim1'));

        // Whether the failure looks like a missing token permission (so the UI
        // can point the user at the exact fix rather than a generic error).
        $isAuthError = $error !== null && str_contains(strtolower($error), 'authentication');

        return [
            'domain' => $domain->name,
            'error' => $error,
            'auth_error' => $isAuthError,
            'records' => $normalized,
            // Sender-authentication summary shown at the top of the modal so the
            // admin can see at a glance which checks pass. null when records
            // could not be fetched (so the UI shows "unknown" not a false "✗").
            'auth_status' => $error !== null ? null : [
                'spf' => $hasSpf,
                'dkim' => $hasDkim,
                'dmarc' => $hasDmarc,
            ],
            // Documented, stable Cloudflare email SPF include — offered when the
            // API returned no SPF record (or could not be reached).
            'spf' => $hasSpf ? null : [
                'type' => 'TXT',
                'name' => $domain->name,
                'value' => 'v=spf1 include:_spf.mx.cloudflare.net ~all',
            ],
            'dmarc' => $hasDmarc ? null : [
                'type' => 'TXT',
                'name' => '_dmarc.'.$domain->name,
                'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain->name,
            ],
        ];
    }
}
