<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareAccount;
use App\Models\DestinationAddress;
use App\Models\Domain;
use App\Models\RoutingRule;

/**
 * Writes Email Routing changes to Cloudflare and mirrors them locally.
 */
class RoutingManager
{
    public function __construct(protected CloudflareAccount $account) {}

    protected function client(): CloudflareClient
    {
        return CloudflareClient::forAccount($this->account);
    }

    public function addDestinationAddress(string $email): DestinationAddress
    {
        $res = $this->client()->createDestinationAddress($email);

        return $this->account->destinationAddresses()->updateOrCreate(
            ['email' => $email],
            ['cf_id' => $res['id'] ?? $res['tag'] ?? null, 'verified_at' => null],
        );
    }

    public function deleteDestinationAddress(DestinationAddress $address): void
    {
        if ($address->cf_id) {
            $this->client()->deleteDestinationAddress($address->cf_id);
        }

        $address->delete();
    }

    /**
     * @param  array<int, string>  $destinations
     */
    public function createForwardRule(Domain $domain, string $matcher, array $destinations): RoutingRule
    {
        $res = $this->client()->createRoutingRule($domain->zone_id, [
            'enabled' => true,
            'name' => $matcher,
            'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => $matcher]],
            'actions' => [['type' => 'forward', 'value' => array_values($destinations)]],
        ]);

        return $this->account->routingRules()->updateOrCreate(
            ['domain_id' => $domain->id, 'cf_id' => $res['tag'] ?? $res['id'] ?? null],
            [
                'name' => $matcher,
                'matcher' => $matcher,
                'actions' => $res['actions'] ?? [['type' => 'forward', 'value' => $destinations]],
                'enabled' => true,
                'is_catch_all' => false,
            ],
        );
    }

    public function toggleRule(RoutingRule $rule, bool $enabled): RoutingRule
    {
        $this->client()->updateRoutingRule($rule->domain->zone_id, $rule->cf_id, [
            'enabled' => $enabled,
            'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => $rule->matcher]],
            'actions' => $rule->actions ?: [['type' => 'drop']],
        ]);

        $rule->update(['enabled' => $enabled]);

        return $rule;
    }

    public function deleteRule(RoutingRule $rule): void
    {
        if ($rule->cf_id) {
            $this->client()->deleteRoutingRule($rule->domain->zone_id, $rule->cf_id);
        }

        $rule->delete();
    }

    /**
     * Route every address on the domain to the inbound Worker (recommended
     * default for a full inbox). See docs §5.0.
     */
    public function setCatchAllToWorker(Domain $domain, string $workerName): Domain
    {
        $this->client()->putCatchAllRule($domain->zone_id, [
            'enabled' => true,
            'name' => 'catch-all -> worker',
            'matchers' => [['type' => 'all']],
            'actions' => [['type' => 'worker', 'value' => [$workerName]]],
        ]);

        $domain->update(['inbound_capture' => 'catch_all']);

        return $domain;
    }

    public function disableCatchAll(Domain $domain): Domain
    {
        $this->client()->putCatchAllRule($domain->zone_id, [
            'enabled' => false,
            'matchers' => [['type' => 'all']],
            'actions' => [['type' => 'drop']],
        ]);

        $domain->update(['inbound_capture' => 'none']);

        return $domain;
    }
}
