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
     * Run all sync steps, resiliently: a missing permission on one step (e.g.
     * Email Routing) does not abort the others. Returns counts + per-step errors.
     *
     * @return array{counts: array{domains:int, addresses:int, rules:int}, errors: array<string, string>}
     */
    public function full(): array
    {
        $counts = ['domains' => 0, 'addresses' => 0, 'rules' => 0];
        $errors = [];

        $steps = [
            'domains' => fn () => $this->syncDomains(),
            'addresses' => fn () => $this->syncDestinationAddresses(),
            'rules' => fn () => $this->syncRoutingRules(),
        ];

        foreach ($steps as $key => $step) {
            try {
                $counts[$key] = $step();
            } catch (CloudflareException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        $this->account->forceFill(['last_synced_at' => now()])->save();

        return ['counts' => $counts, 'errors' => $errors];
    }

    public function syncDomains(): int
    {
        $zones = $this->client()->listZones();
        $count = 0;

        foreach ($zones as $zone) {
            $zoneId = (string) $zone['id'];
            $attributes = [
                'name' => $zone['name'] ?? '',
                'status' => $zone['status'] ?? null,
                'routing_enabled' => $this->routingEnabled($zoneId),
                'last_synced_at' => now(),
            ];

            // Null means the sending API could not be read. Leave the stored
            // flag alone so a 403 does not flip a working domain back to off.
            $sendingEnabled = $this->sendingEnabled($zoneId);
            if ($sendingEnabled !== null) {
                $attributes['sending_enabled'] = $sendingEnabled;
            }

            $this->account->domains()->updateOrCreate(
                ['zone_id' => $zoneId],
                $attributes,
            );
            $count++;
        }

        return $count;
    }

    /**
     * Receiving is on when Email Routing reports enabled/ready. Tokens that
     * can manage rules often still get 403 from the settings endpoint; an
     * enabled rule (including catch-all) is the same signal.
     */
    protected function routingEnabled(string $zoneId): bool
    {
        try {
            $routing = $this->client()->getRoutingSettings($zoneId);

            return ($routing['enabled'] ?? false) === true
                || ($routing['status'] ?? null) === 'ready';
        } catch (CloudflareException) {
            // Fall through to the rules list.
        }

        try {
            foreach ($this->client()->listRoutingRules($zoneId) as $rule) {
                if (($rule['enabled'] ?? true) === true) {
                    return true;
                }
            }
        } catch (CloudflareException) {
            // Can't tell. Leave the column off rather than failing the sync.
        }

        return false;
    }

    /**
     * True when any Email Sending subdomain on the zone is enabled.
     * Null when the endpoint cannot be read.
     */
    protected function sendingEnabled(string $zoneId): ?bool
    {
        try {
            $subdomains = $this->client()->listSendingSubdomains($zoneId);
        } catch (CloudflareException) {
            return null;
        }

        foreach ($subdomains as $subdomain) {
            if (($subdomain['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
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
