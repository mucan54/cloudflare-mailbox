<?php

namespace Tests\Feature;

use App\Mail\OutboundMail;
use App\Models\CloudflareAccount;
use App\Services\Mail\EmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class EmailSenderTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $driver = 'api'): CloudflareAccount
    {
        return CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok', 'sending_driver' => $driver,
        ]);
    }

    private function fakeSend(array $result, int $status = 200): void
    {
        Http::fake([
            '*/email/sending/send' => Http::response([
                'success' => $status < 300,
                'errors' => $status < 300 ? [] : [['code' => 10001, 'message' => 'bad']],
                'result' => $status < 300 ? $result : null,
            ], $status),
        ]);
    }

    public function test_api_delivered_is_logged(): void
    {
        $this->fakeSend(['delivered' => ['to@x.com'], 'queued' => [], 'permanent_bounces' => []]);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'subject' => 'Hi', 'text' => 'Hello',
        ]);

        $this->assertSame('delivered', $sent->status);
        $this->assertSame('api', $sent->driver);
        $this->assertDatabaseHas('sent_emails', ['id' => $sent->id, 'status' => 'delivered']);
    }

    public function test_api_queued_status(): void
    {
        $this->fakeSend(['delivered' => [], 'queued' => ['to@x.com'], 'permanent_bounces' => []]);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'text' => 'Hello',
        ]);

        $this->assertSame('queued', $sent->status);
    }

    public function test_api_bounced_status(): void
    {
        $this->fakeSend(['delivered' => [], 'queued' => [], 'permanent_bounces' => ['to@x.com']]);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'text' => 'Hello',
        ]);

        $this->assertSame('bounced', $sent->status);
    }

    public function test_api_error_is_failed(): void
    {
        $this->fakeSend([], 400);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'text' => 'Hello',
        ]);

        $this->assertSame('failed', $sent->status);
        $this->assertNotNull($sent->error);
    }

    public function test_empty_response_is_failed_not_queued(): void
    {
        // Cloudflare "success" but every bucket empty = recipient suppressed /
        // not accepted. Must not be recorded as a successful "queued" send.
        $this->fakeSend(['delivered' => [], 'queued' => [], 'permanent_bounces' => []]);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'text' => 'Hello',
        ]);

        $this->assertSame('failed', $sent->status);
        $this->assertStringContainsString('Suppressions', $sent->error);
    }

    public function test_sending_disabled_error_gets_actionable_hint(): void
    {
        Http::fake([
            '*/email/sending/send' => Http::response([
                'success' => false,
                'errors' => [['code' => 10203, 'message' => 'email.sending.error.email.sending_disabled']],
                'result' => null,
            ], 403),
        ]);

        $sent = (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'text' => 'Hello',
        ]);

        $this->assertSame('failed', $sent->status);
        $this->assertStringContainsString('Onboard Domain', $sent->error);
        $this->assertStringContainsString('dash.cloudflare.com/acc1/email/sending', $sent->error);
    }

    public function test_size_limit_is_enforced(): void
    {
        $this->expectException(RuntimeException::class);

        (new EmailSender)->send($this->account(), [
            'from' => 'me@a.com',
            'to' => 'to@x.com',
            'text' => str_repeat('a', 6 * 1024 * 1024),
        ]);
    }

    public function test_smtp_driver_uses_mailer(): void
    {
        Mail::fake();

        $sent = (new EmailSender)->send($this->account('smtp'), [
            'from' => 'me@a.com', 'to' => 'to@x.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>',
        ]);

        Mail::assertSent(OutboundMail::class);
        $this->assertSame('queued', $sent->status);
        $this->assertSame('smtp', $sent->driver);
    }
}
