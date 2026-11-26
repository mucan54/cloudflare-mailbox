<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Mail\SentStatusReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SentStatusReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_rows_take_the_final_cloudflare_status(): void
    {
        Http::fake([
            '*/graphql' => Http::response([
                'data' => [
                    'viewer' => [
                        'accounts' => [[
                            'emailSendingAdaptive' => [
                                ['messageId' => '<one@a.com>', 'status' => 'delivered', 'errorCause' => null, 'errorDetail' => null],
                                ['messageId' => '<two@a.com>', 'status' => 'sent', 'errorCause' => null, 'errorDetail' => null],
                                ['messageId' => '<three@a.com>', 'status' => 'deliveryFailed', 'errorCause' => 'upstream', 'errorDetail' => '550 rejected'],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $account = CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok',
        ]);

        $delivered = $this->queued($account, '<one@a.com>');
        $sent = $this->queued($account, '<two@a.com>');
        $bounced = $this->queued($account, '<three@a.com>');
        $stillQueued = $this->queued($account, '<pending@a.com>');

        $updated = (new SentStatusReconciler)->reconcile($account);

        $this->assertSame(3, $updated);
        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('bounced', $bounced->fresh()->status);
        $this->assertSame('550 rejected', $bounced->fresh()->error);
        $this->assertSame('queued', $stillQueued->fresh()->status);
    }

    private function queued(CloudflareAccount $account, string $messageId)
    {
        return $account->sentEmails()->create([
            'driver' => 'api',
            'from_email' => 'me@a.com',
            'to' => ['to@x.com'],
            'status' => 'queued',
            'sent_at' => now()->subHour(),
            'cf_response' => ['message_id' => $messageId],
        ]);
    }
}
