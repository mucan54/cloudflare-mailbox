<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IncomingEmailTest extends TestCase
{
    use RefreshDatabase;

    private function account(): CloudflareAccount
    {
        return CloudflareAccount::create([
            'name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok', 'webhook_secret' => 'shhh-secret-value',
        ]);
    }

    private function postWebhook(CloudflareAccount $account, array $payload, ?string $signature = null, ?string $timestamp = null): TestResponse
    {
        $body = json_encode($payload);
        $timestamp ??= (string) (time() * 1000);
        $signature ??= WebhookSignature::sign($account->webhook_secret, $timestamp, $body);

        return $this->call('POST', '/api/cf/incoming', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CF_ACCOUNT' => $account->account_id,
            'HTTP_X_CF_SIGNATURE' => $signature,
            'HTTP_X_CF_TIMESTAMP' => $timestamp,
        ], $body);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'message_id' => '<abc@x.com>',
            'envelope_from' => 'sender@x.com',
            'envelope_to' => 'support@a.com',
            'subject' => 'Hello',
            'text' => 'Hi there',
            'html' => '<p>Hi there</p>',
            'from' => ['address' => 'sender@x.com', 'name' => 'Sender'],
            'raw_size' => 1234,
        ], $overrides);
    }

    public function test_valid_signature_stores_email(): void
    {
        $account = $this->account();
        $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        $this->postWebhook($account, $this->payload())->assertStatus(202);

        $this->assertDatabaseHas('emails', [
            'cloudflare_account_id' => $account->id,
            'to_email' => 'support@a.com',
            'from_email' => 'sender@x.com',
            'subject' => 'Hello',
        ]);
    }

    public function test_mailbox_is_resolved_by_recipient(): void
    {
        $account = $this->account();
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $mailbox = $account->mailboxes()->create([
            'domain_id' => $domain->id, 'email' => 'support@a.com', 'password' => 'secret',
        ]);

        $this->postWebhook($account, $this->payload())->assertStatus(202);

        $this->assertDatabaseHas('emails', ['to_email' => 'support@a.com', 'mailbox_id' => $mailbox->id]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $account = $this->account();

        $this->postWebhook($account, $this->payload(), signature: 'deadbeef')->assertStatus(401);
        $this->assertDatabaseCount('emails', 0);
    }

    public function test_ingest_is_idempotent(): void
    {
        $account = $this->account();

        $this->postWebhook($account, $this->payload())->assertStatus(202);
        $this->postWebhook($account, $this->payload())->assertStatus(202);

        $this->assertDatabaseCount('emails', 1);
    }

    public function test_attachment_is_stored(): void
    {
        Storage::fake('local');
        config()->set('cloudflare.attachments_disk', 'local');
        $account = $this->account();

        $payload = $this->payload([
            'attachments' => [[
                'filename' => 'note.txt',
                'mimeType' => 'text/plain',
                'size' => 5,
                'content' => base64_encode('hello'),
            ]],
        ]);

        $this->postWebhook($account, $payload)->assertStatus(202);

        $this->assertDatabaseHas('attachments', ['filename' => 'note.txt', 'mime_type' => 'text/plain']);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $account = $this->account();
        $old = (string) ((time() - 10000) * 1000);

        $this->postWebhook($account, $this->payload(), timestamp: $old)->assertStatus(401);
    }
}
