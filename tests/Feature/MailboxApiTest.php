<?php

namespace Tests\Feature;

use App\Jobs\StoreIncomingEmail;
use App\Models\Attachment;
use App\Models\CloudflareAccount;
use App\Models\Email;
use App\Models\Mailbox;
use App\Models\SentEmail;
use App\Notifications\IncomingMailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxApiTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(array $attrs = []): Mailbox
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com', 'sending_enabled' => true]);

        return $account->mailboxes()->create(array_merge([
            'domain_id' => $domain->id,
            'email' => 'me@a.com',
            'password' => 'password123',
            'login_enabled' => true,
        ], $attrs));
    }

    public function test_login_returns_token(): void
    {
        $this->mailbox();

        $this->postJson('/api/mailbox/login', ['email' => 'me@a.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'mailbox' => ['id', 'email']]);
    }

    public function test_login_fails_for_wrong_password(): void
    {
        $this->mailbox();

        $this->postJson('/api/mailbox/login', ['email' => 'me@a.com', 'password' => 'nope'])
            ->assertStatus(422);
    }

    public function test_login_blocked_when_disabled(): void
    {
        $this->mailbox(['login_enabled' => false]);

        $this->postJson('/api/mailbox/login', ['email' => 'me@a.com', 'password' => 'password123'])
            ->assertStatus(422);
    }

    public function test_inbox_is_scoped_to_the_mailbox(): void
    {
        $mailbox = $this->mailbox();
        $other = $this->mailbox(['email' => 'other@a.com']);

        $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k1', 'subject' => 'Mine', 'received_at' => now()]);
        $other->emails()->create(['cloudflare_account_id' => $other->cloudflare_account_id, 'ingest_key' => 'k2', 'subject' => 'Theirs', 'received_at' => now()]);

        Sanctum::actingAs($mailbox);

        $this->getJson('/api/mailbox/emails')
            ->assertOk()
            ->assertJsonFragment(['subject' => 'Mine'])
            ->assertJsonMissing(['subject' => 'Theirs']);
    }

    public function test_show_marks_read(): void
    {
        $mailbox = $this->mailbox();
        $email = $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k1', 'subject' => 'Hi', 'received_at' => now()]);

        Sanctum::actingAs($mailbox);
        $this->getJson("/api/mailbox/emails/{$email->id}")->assertOk();

        $this->assertNotNull($email->fresh()->read_at);
    }

    public function test_send_from_mailbox(): void
    {
        Http::fake(['*/email/sending/send' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['delivered' => ['x@y.com'], 'queued' => [], 'permanent_bounces' => []],
        ])]);

        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->postJson('/api/mailbox/send', [
            'to' => ['x@y.com'], 'subject' => 'Hey', 'html' => '<p>Hi</p>',
        ])->assertStatus(201)->assertJson(['status' => 'delivered']);

        $this->assertDatabaseHas('sent_emails', [
            'mailbox_id' => $mailbox->id, 'from_email' => 'me@a.com', 'status' => 'delivered',
        ]);
    }

    public function test_send_with_attachment_forwards_it_and_stores_a_copy(): void
    {
        Storage::fake('local');
        Http::fake(['*/email/sending/send' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['delivered' => ['x@y.com'], 'queued' => [], 'permanent_bounces' => []],
        ])]);

        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $content = base64_encode('hello file');

        $res = $this->postJson('/api/mailbox/send', [
            'to' => ['x@y.com'], 'subject' => 'Hey', 'html' => '<p>Hi</p>',
            'attachments' => [
                ['filename' => 'note.txt', 'type' => 'text/plain', 'content' => $content, 'size' => 10],
            ],
        ])->assertStatus(201);

        // Forwarded to Cloudflare with the base64 content.
        Http::assertSent(function ($request) use ($content) {
            return str_contains($request->url(), '/email/sending/send')
                && ($request['attachments'][0]['filename'] ?? null) === 'note.txt'
                && ($request['attachments'][0]['content'] ?? null) === $content;
        });

        // A copy is persisted against the sent message.
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => SentEmail::class,
            'attachable_id' => $res->json('id'),
            'filename' => 'note.txt',
        ]);
    }

    public function test_attachment_download_is_scoped_to_the_mailbox(): void
    {
        Storage::fake('local');

        $mailbox = $this->mailbox();
        $other = $this->mailbox(['email' => 'other@a.com']);

        $email = $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'a1', 'received_at' => now()]);
        Storage::disk('local')->put('attachments/x/file.txt', 'secret bytes');
        $att = Attachment::create([
            'attachable_type' => Email::class, 'attachable_id' => $email->id,
            'filename' => 'file.txt', 'mime_type' => 'text/plain', 'size' => 12,
            'storage_disk' => 'local', 'storage_path' => 'attachments/x/file.txt',
        ]);

        // Owner can download.
        Sanctum::actingAs($mailbox);
        $this->get("/api/mailbox/attachments/{$att->id}/download")->assertOk();

        // A different mailbox cannot.
        Sanctum::actingAs($other);
        $this->get("/api/mailbox/attachments/{$att->id}/download")->assertForbidden();
    }

    public function test_update_profile_persists_display_name_and_signature(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->putJson('/api/mailbox/me', [
            'display_name' => 'Jane Doe',
            'signature' => "--\nJane Doe\nAcme Inc.",
        ])->assertOk()->assertJson(['mailbox' => [
            'display_name' => 'Jane Doe',
            'signature' => "--\nJane Doe\nAcme Inc.",
        ]]);

        $this->assertDatabaseHas('mailboxes', [
            'id' => $mailbox->id,
            'display_name' => 'Jane Doe',
            'signature' => "--\nJane Doe\nAcme Inc.",
        ]);
    }

    public function test_send_includes_sender_display_name(): void
    {
        Http::fake(['*/email/sending/send' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['delivered' => ['x@y.com'], 'queued' => [], 'permanent_bounces' => []],
        ])]);

        $mailbox = $this->mailbox(['display_name' => 'Jane Doe']);
        Sanctum::actingAs($mailbox);

        $this->postJson('/api/mailbox/send', [
            'to' => ['x@y.com'], 'subject' => 'Hey', 'html' => '<p>Hi</p>',
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/email/sending/send')
                && $request['from'] === ['address' => 'me@a.com', 'name' => 'Jane Doe'];
        });
    }

    public function test_inbox_folders_filter(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k1', 'subject' => 'InboxMail', 'received_at' => now()]);
        $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k2', 'subject' => 'StarMail', 'starred' => true, 'received_at' => now()]);
        $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k3', 'subject' => 'TrashMail', 'folder' => 'trash', 'received_at' => now()]);

        Sanctum::actingAs($mailbox);

        $this->getJson('/api/mailbox/emails?folder=inbox')
            ->assertOk()
            ->assertJsonFragment(['subject' => 'InboxMail'])
            ->assertJsonMissing(['subject' => 'TrashMail']);

        $this->getJson('/api/mailbox/emails?folder=starred')
            ->assertOk()
            ->assertJsonFragment(['subject' => 'StarMail'])
            ->assertJsonMissing(['subject' => 'InboxMail']);

        $this->getJson('/api/mailbox/emails?folder=trash')
            ->assertOk()
            ->assertJsonFragment(['subject' => 'TrashMail'])
            ->assertJsonMissing(['subject' => 'InboxMail']);
    }

    public function test_move_email_to_trash_and_star(): void
    {
        $mailbox = $this->mailbox();
        $email = $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'k1', 'subject' => 'Hi', 'received_at' => now()]);

        Sanctum::actingAs($mailbox);

        $this->patchJson("/api/mailbox/emails/{$email->id}", ['starred' => true, 'folder' => 'trash'])
            ->assertOk();

        $this->assertDatabaseHas('emails', ['id' => $email->id, 'starred' => true, 'folder' => 'trash']);
    }

    public function test_sent_show_returns_full_body(): void
    {
        $mailbox = $this->mailbox();
        $sent = $mailbox->sentEmails()->create([
            'cloudflare_account_id' => $mailbox->cloudflare_account_id,
            'driver' => 'api', 'from_email' => 'me@a.com', 'to' => ['x@y.com'],
            'subject' => 'Outgoing', 'text_body' => 'Body here', 'status' => 'delivered', 'sent_at' => now(),
        ]);

        Sanctum::actingAs($mailbox);

        $this->getJson("/api/mailbox/sent/{$sent->id}")
            ->assertOk()
            ->assertJsonPath('email.subject', 'Outgoing')
            ->assertJsonPath('email.text_body', 'Body here')
            ->assertJsonPath('email.to_email', 'x@y.com');
    }

    public function test_recipient_suggestions_from_history_and_contacts(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->emails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'ingest_key' => 'r1', 'from_email' => 'jurgen@enerprax.de', 'from_name' => 'Jürgen', 'received_at' => now()]);
        $mailbox->sentEmails()->create(['cloudflare_account_id' => $mailbox->cloudflare_account_id, 'driver' => 'api', 'from_email' => 'me@a.com', 'to' => ['ayse@magazam.net'], 'subject' => 'Hi', 'status' => 'queued', 'sent_at' => now()]);
        $mailbox->contacts()->create(['name' => 'Zeynep', 'email' => 'zeynep@studio.co']);

        Sanctum::actingAs($mailbox);

        $this->getJson('/api/mailbox/recipients?q=jur')->assertOk()->assertJsonFragment(['email' => 'jurgen@enerprax.de']);
        $this->getJson('/api/mailbox/recipients?q=ayse')->assertOk()->assertJsonFragment(['email' => 'ayse@magazam.net']);
        $this->getJson('/api/mailbox/recipients?q=zey')->assertOk()->assertJsonFragment(['email' => 'zeynep@studio.co', 'name' => 'Zeynep']);
    }

    public function test_push_subscribe(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->postJson('/api/mailbox/push-subscribe', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
            'contentEncoding' => 'aes128gcm',
        ])->assertOk();

        // Must be aes128gcm, not the legacy aesgcm that iOS silently drops.
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => 'https://push.example/abc',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    public function test_same_device_registers_under_multiple_mailboxes(): void
    {
        $a = $this->mailbox(['email' => 'a@a.com']);
        $b = $this->mailbox(['email' => 'b@a.com']);
        $endpoint = 'https://push.example/same-device';
        $body = ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 't'], 'contentEncoding' => 'aes128gcm'];

        Sanctum::actingAs($a);
        $this->postJson('/api/mailbox/push-subscribe', $body)->assertOk();

        Sanctum::actingAs($b);
        $this->postJson('/api/mailbox/push-subscribe', $body)->assertOk();

        // Both mailboxes must keep a subscription for the shared device — the
        // second subscribe must NOT steal it from the first.
        $this->assertSame(1, $a->pushSubscriptions()->count(), 'A lost its subscription');
        $this->assertSame(1, $b->pushSubscriptions()->count(), 'B has no subscription');
        $this->assertDatabaseCount('push_subscriptions', 2);
    }

    public function test_push_test_reports_no_subscriptions(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->postJson('/api/mailbox/push-test')
            ->assertOk()
            ->assertJson(['sent' => 0, 'reason' => 'no_subscriptions']);
    }

    public function test_incoming_mail_notifies_mailbox(): void
    {
        Notification::fake();

        $mailbox = $this->mailbox(['email' => 'support@a.com']);
        $mailbox->updatePushSubscription('https://push.example/abc', 'p256dh', 'auth');

        (new StoreIncomingEmail($mailbox->cloudflare_account_id, [
            'message_id' => '<x@y>', 'envelope_to' => 'support@a.com', 'envelope_from' => 's@x.com',
            'subject' => 'Ping', 'text' => 'hi',
        ]))->handle();

        Notification::assertSentTo($mailbox, IncomingMailNotification::class);
    }
}
