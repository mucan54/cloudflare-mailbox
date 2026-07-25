<?php

namespace App\Services\Cloudflare;

use App\Models\Domain;

/**
 * Provisions the DNS records that let native mail clients auto-configure and
 * connect for a domain:
 *   - autodiscover.<domain> / autoconfig.<domain> CNAME -> app host (serves the
 *     autodiscover XML; proxied so Cloudflare terminates TLS).
 *   - mail.<domain> CNAME -> the IMAP/SMTP bridge host (grey-cloud: raw TCP).
 *   - _imaps._tcp / _submission._tcp SRV -> mail.<domain>.
 */
class MailClientDns
{
    /**
     * The records that should exist for a domain.
     *
     * @return array<int, array<string, mixed>>
     */
    public function records(Domain $domain): array
    {
        $name = $domain->name;
        $appHost = (string) config('cloudflare.mail_client.app_host');
        $serverHost = (string) config('cloudflare.mail_client.server_host');
        $imapPort = (int) config('cloudflare.mail_client.imap_port', 993);
        $smtpPort = (int) config('cloudflare.mail_client.smtp_port', 587);
        $mailHost = 'mail.'.$name;

        $records = [
            ['type' => 'CNAME', 'name' => 'autodiscover.'.$name, 'content' => $appHost, 'proxied' => true, 'ttl' => 1],
            ['type' => 'CNAME', 'name' => 'autoconfig.'.$name, 'content' => $appHost, 'proxied' => true, 'ttl' => 1],
        ];

        // mail.<domain> + SRV only make sense once the bridge host is known.
        if ($serverHost !== '') {
            $records[] = ['type' => 'CNAME', 'name' => $mailHost, 'content' => $serverHost, 'proxied' => false, 'ttl' => 1];
            $records[] = $this->srv('_imaps', $name, $imapPort, $mailHost);
            $records[] = $this->srv('_submission', $name, $smtpPort, $mailHost);
        }

        return $records;
    }

    public function provision(Domain $domain): void
    {
        $client = CloudflareClient::forAccount($domain->account);
        foreach ($this->records($domain) as $record) {
            $client->upsertDnsRecord($domain->zone_id, $record);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function srv(string $service, string $zone, int $port, string $target): array
    {
        return [
            'type' => 'SRV',
            'name' => $service.'._tcp.'.$zone,
            'ttl' => 1,
            'data' => [
                'service' => $service,
                'proto' => '_tcp',
                'name' => $zone,
                'priority' => 1,
                'weight' => 1,
                'port' => $port,
                'target' => $target,
            ],
        ];
    }
}
