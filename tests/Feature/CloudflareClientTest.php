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
