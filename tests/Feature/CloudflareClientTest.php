<?php

namespace Tests\Feature;

use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareClientTest extends TestCase
{
    private function ok(array $result, array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => $result,
        ], $extra);
    }

    public function test_verify_token_returns_result(): void
    {
        Http::fake([
            '*/user/tokens/verify' => Http::response($this->ok(['status' => 'active'])),
        ]);

        $client = new CloudflareClient('tok', 'acc');

        $this->assertSame('active', $client->verifyToken()['status']);
    }

    public function test_list_accounts(): void
    {
        Http::fake([
            '*/accounts*' => Http::response($this->ok([
                ['id' => 'acc1', 'name' => 'Acme'],
            ])),
        ]);

        $client = new CloudflareClient('tok');
        $accounts = $client->listAccounts();

        $this->assertCount(1, $accounts);
        $this->assertSame('acc1', $accounts[0]['id']);
    }

    public function test_email_routing_dns_normalises_flat_list(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response($this->ok([
                ['type' => 'MX', 'name' => 'a.com', 'content' => 'route1.mx.cloudflare.net', 'priority' => 1],
                ['type' => 'TXT', 'name' => 'a.com', 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all'],
            ])),
        ]);

        $records = (new CloudflareClient('tok', 'acc'))->emailRoutingDns('zone1');

        $this->assertCount(2, $records);
        $this->assertSame('MX', $records[0]['type']);
    }

    public function test_email_routing_dns_normalises_record_wrapper(): void
    {
        Http::fake([
            '*/email/routing/dns' => Http::response($this->ok([
                'record' => [
                    ['type' => 'TXT', 'name' => 'a.com', 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all'],
                ],
            ])),
        ]);

        $records = (new CloudflareClient('tok', 'acc'))->emailRoutingDns('zone1');

        $this->assertCount(1, $records);
        $this->assertSame('TXT', $records[0]['type']);
    }

    public function test_list_zones_follows_pagination(): void
    {
        $page = 0;
        Http::fake(function () use (&$page) {
            $page++;
            $totalPages = 2;
            $result = $page === 1
                ? [['id' => 'z1', 'name' => 'a.com']]
                : [['id' => 'z2', 'name' => 'b.com']];

            return Http::response($this->ok($result, [
                'result_info' => ['page' => $page, 'total_pages' => $totalPages],
            ]));
        });

        $client = new CloudflareClient('tok', 'acc');
        $zones = $client->listZones();

        $this->assertCount(2, $zones);
        $this->assertSame(['z1', 'z2'], array_column($zones, 'id'));
    }

    public function test_send_posts_message_and_returns_result(): void
    {
        Http::fake([
            '*/email/sending/send' => Http::response($this->ok([
                'delivered' => ['to@example.com'],
                'queued' => [],
                'permanent_bounces' => [],
            ])),
        ]);

        $client = new CloudflareClient('tok', 'acc');
        $result = $client->send([
            'from' => 'me@domain.com',
            'to' => 'to@example.com',
            'subject' => 'Hi',
            'text' => 'Hello',
        ]);

        $this->assertSame(['to@example.com'], $result['delivered']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/accounts/acc/email/sending/send')
            && $request['from'] === 'me@domain.com');
    }

    public function test_list_suppressions_returns_result(): void
    {
        Http::fake([
            '*/email/sending/suppressions' => Http::response($this->ok([
                ['id' => 's1', 'email' => 'bad@example.com', 'reason' => 'spam_complaint'],
            ])),
        ]);

        $rows = (new CloudflareClient('tok', 'acc'))->listSuppressions();

        $this->assertSame('bad@example.com', $rows[0]['email']);
    }

    public function test_delete_suppression_hits_encoded_path(): void
    {
        Http::fake(['*' => Http::response($this->ok([]))]);

        (new CloudflareClient('tok', 'acc'))->deleteSuppression('bad@example.com');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/accounts/acc/email/sending/suppressions/bad%40example.com'));
    }

    public function test_api_error_throws_cloudflare_exception(): void
    {
        Http::fake([
            '*/email/sending/send' => Http::response([
                'success' => false,
                'errors' => [['code' => 10001, 'message' => 'invalid_request_schema']],
                'result' => null,
            ], 400),
        ]);

        $this->expectException(CloudflareException::class);

        (new CloudflareClient('tok', 'acc'))->send(['from' => 'x']);
    }
}
