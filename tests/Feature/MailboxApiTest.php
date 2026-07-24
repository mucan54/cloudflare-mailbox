<?php

namespace Tests\Feature;

use App\Jobs\StoreIncomingEmail;
use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use App\Notifications\IncomingMailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

    public function test_push_subscribe(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->postJson('/api/mailbox/push-subscribe', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/abc']);
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
