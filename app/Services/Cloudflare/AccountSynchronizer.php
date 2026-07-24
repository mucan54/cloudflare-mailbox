<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareAccount;
use App\Models\Domain;

/**
 * Pulls the current Cloudflare state (zones, routing rules, destination
 * addresses) into the local database as an idempotent, up-sertable cache.
 */
class AccountSynchronizer
{
    public function __construct(protected CloudflareAccount $account) {}

    protected function client(): CloudflareClient
    {
        return CloudflareClient::forAccount($this->account);
    }

    /**
     * Run all sync steps. Returns per-step counts.
     *
     * @return array{domains:int, addresses:int, rules:int}
     */
    public function full(): array
    {
        $counts = [
            'domains' => $this->syncDomains(),
            'addresses' => $this->syncDestinationAddresses(),
            'rules' => $this->syncRoutingRules(),
        ];

        $this->account->forceFill(['last_synced_at' => now()])->save();

        return $counts;
    }

    public function syncDomains(): int
    {
        $zones = $this->client()->listZones();
        $count = 0;

        foreach ($zones as $zone) {
            $routingEnabled = false;

            try {
                $routing = $this->client()->getRoutingSettings($zone['id']);
                $routingEnabled = ($routing['enabled'] ?? false) === true
                    || ($routing['status'] ?? null) === 'ready';
            } catch (CloudflareException) {
                // Routing not enabled for this zone yet — leave as false.
            }

            $this->account->domains()->updateOrCreate(
                ['zone_id' => $zone['id']],
                [
                    'name' => $zone['name'] ?? '',
                    'status' => $zone['status'] ?? null,
                    'routing_enabled' => $routingEnabled,
                    'last_synced_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    public function syncDestinationAddresses(): int
    {
        $addresses = $this->client()->listDestinationAddresses();
        $count = 0;

        foreach ($addresses as $addr) {
            $this->account->destinationAddresses()->updateOrCreate(
                ['email' => $addr['email']],
                [
                    'cf_id' => $addr['id'] ?? $addr['tag'] ?? null,
                    'verified_at' => ! empty($addr['verified']) ? now() : null,
                ],
            );
            $count++;
        }

        return $count;
    }

    public function syncRoutingRules(): int
    {
        $count = 0;

        $this->account->domains()
            ->whereNotNull('zone_id')
            ->get()
            ->each(function (Domain $domain) use (&$count) {
                foreach ($this->client()->listRoutingRules($domain->zone_id) as $rule) {
                    $matcher = collect($rule['matchers'] ?? [])
                        ->firstWhere('type', 'literal')['value'] ?? null;
                    $isCatchAll = collect($rule['matchers'] ?? [])
                        ->contains(fn ($m) => ($m['type'] ?? null) === 'all');

                    $this->account->routingRules()->updateOrCreate(
                        [
                            'domain_id' => $domain->id,
                            'cf_id' => $rule['tag'] ?? $rule['id'] ?? null,
                        ],
                        [
                            'name' => $rule['name'] ?? null,
                            'matcher' => $matcher,
                            'actions' => $rule['actions'] ?? [],
                            'enabled' => $rule['enabled'] ?? true,
                            'priority' => $rule['priority'] ?? 0,
                            'is_catch_all' => $isCatchAll,
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }
}
