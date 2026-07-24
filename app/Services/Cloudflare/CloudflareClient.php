<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin, testable wrapper over the Cloudflare v4 REST API used by this app.
 * Built on Laravel's Http client so it mocks cleanly with Http::fake().
 */
class CloudflareClient
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $accountId = null,
        protected ?string $base = null,
    ) {
        $this->base ??= (string) config('cloudflare.api_base');
    }

    public static function forAccount(CloudflareAccount $account): self
    {
        return new self($account->api_token, $account->account_id);
    }

    public function withToken(string $token): self
    {
        return new self($token, $this->accountId, $this->base);
    }

    public function forAccountId(string $accountId): self
    {
        return new self($this->token, $accountId, $this->base);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->base)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->retry(3, 200, throw: false)
            ->timeout(30);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->unwrap($this->http()->get($path, $query), "GET {$path}");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $path, array $payload = []): array
    {
        return $this->unwrap($this->http()->post($path, $payload), "POST {$path}");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function put(string $path, array $payload = []): array
    {
        return $this->unwrap($this->http()->put($path, $payload), "PUT {$path}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function delete(string $path): array
    {
        return $this->unwrap($this->http()->delete($path), "DELETE {$path}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function unwrap(Response $response, string $context): array
    {
        $json = $response->json() ?? [];

        if (! ($json['success'] ?? false)) {
            throw CloudflareException::fromResponse(
                $context,
                $json['errors'] ?? [['message' => $response->body()]],
                $response->status(),
            );
        }

        return $json;
    }

    // --- Onboarding -------------------------------------------------------

    /**
     * Verify the token is valid/active. Returns the `result` (status etc.).
     *
     * @return array<string, mixed>
     */
    public function verifyToken(): array
    {
        return $this->get('/user/tokens/verify')['result'] ?? [];
    }

    /**
     * List accounts this token can access (auto account detection).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        return $this->get('/accounts', ['per_page' => 50])['result'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function permissionGroups(): array
    {
        return $this->get('/user/tokens/permission_groups', ['per_page' => 100])['result'] ?? [];
    }

    // --- Zones (domains) --------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listZones(): array
    {
        $all = [];
        $page = 1;

        do {
            $res = $this->get('/zones', array_filter([
                'per_page' => 50,
                'page' => $page,
                'account.id' => $this->accountId, // omitted when not yet known (onboarding)
            ], fn ($v) => $v !== null));
            $all = array_merge($all, $res['result'] ?? []);
            $info = $res['result_info'] ?? [];
            $totalPages = (int) ($info['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRoutingSettings(string $zoneId): array
    {
        return $this->get("/zones/{$zoneId}/email/routing")['result'] ?? [];
    }

    /**
     * The DNS records Cloudflare recommends for email on this zone (MX + SPF,
     * and DKIM when Cloudflare manages it). Used to guide sender-authentication
     * setup so outbound mail is not bounced by strict receivers (Outlook/Gmail).
     *
     * The API has shipped two response shapes — a flat record list and an
     * `{ record: [...] }` wrapper — so we normalise both.
     *
     * @return array<int, array<string, mixed>>
     */
    public function emailRoutingDns(string $zoneId): array
    {
        $result = $this->get("/zones/{$zoneId}/email/routing/dns")['result'] ?? [];

        if (isset($result['record']) && is_array($result['record'])) {
            return $result['record'];
        }

        return array_is_list($result) ? $result : [];
    }

    // --- Destination addresses (account-level) ---------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDestinationAddresses(): array
    {
        return $this->get("/accounts/{$this->accountId}/email/routing/addresses", [
            'per_page' => 50,
        ])['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createDestinationAddress(string $email): array
    {
        return $this->post("/accounts/{$this->accountId}/email/routing/addresses", [
            'email' => $email,
        ])['result'] ?? [];
    }

    public function deleteDestinationAddress(string $id): void
    {
        $this->delete("/accounts/{$this->accountId}/email/routing/addresses/{$id}");
    }

    // --- Routing rules (zone-level) --------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRoutingRules(string $zoneId): array
    {
        return $this->get("/zones/{$zoneId}/email/routing/rules", [
            'per_page' => 50,
        ])['result'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createRoutingRule(string $zoneId, array $payload): array
    {
        return $this->post("/zones/{$zoneId}/email/routing/rules", $payload)['result'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRoutingRule(string $zoneId, string $ruleId, array $payload): array
    {
        return $this->put("/zones/{$zoneId}/email/routing/rules/{$ruleId}", $payload)['result'] ?? [];
    }

    public function deleteRoutingRule(string $zoneId, string $ruleId): void
    {
        $this->delete("/zones/{$zoneId}/email/routing/rules/{$ruleId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getCatchAllRule(string $zoneId): array
    {
        return $this->get("/zones/{$zoneId}/email/routing/rules/catch_all")['result'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function putCatchAllRule(string $zoneId, array $payload): array
    {
        return $this->put("/zones/{$zoneId}/email/routing/rules/catch_all", $payload)['result'] ?? [];
    }

    // --- Sending ----------------------------------------------------------

    /**
     * POST /accounts/{id}/email/sending/send
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed> the `result` (delivered/queued/permanent_bounces)
     */
    public function send(array $message): array
    {
        return $this->post("/accounts/{$this->accountId}/email/sending/send", $message)['result'] ?? [];
    }
}
