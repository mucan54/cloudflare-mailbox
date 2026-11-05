<?php

namespace Tests\Feature;

use App\Jobs\StoreIncomingEmail;
use App\Models\CloudflareAccount;
use App\Models\Email;
use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxAvatarTest extends TestCase
{
    use RefreshDatabase;

    private const CF_PASS = 'mx.cloudflare.net; dkim=pass header.d=a.com header.s=cf-bounce; spf=pass smtp.mailfrom=a.com; dmarc=pass header.from=a.com';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cloudflare.attachments_disk' => 'local', 'cloudflare.avatars_disk' => null]);
    }

    private function mailbox(string $email = 'me@a.com'): Mailbox
    {
        $account = CloudflareAccount::firstOrCreate(['account_id' => 'acc1'], ['name' => 'Acme', 'api_token' => 'tok']);
        $domain = $account->domains()->firstOrCreate(['name' => 'a.com'], ['zone_id' => 'z1', 'sending_enabled' => true]);

        return $account->mailboxes()->create([
            'domain_id' => $domain->id,
            'email' => $email,
            'display_name' => 'Ada Lovelace',
            'password' => 'password123',
            'login_enabled' => true,
        ]);
    }

    private function upload(Mailbox $mailbox, int $w = 800, int $h = 600)
    {
        Sanctum::actingAs($mailbox);

        return $this->post('/api/mailbox/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', $w, $h),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_normalizes_to_a_square_jpeg_and_returns_its_url(): void
    {
        $mailbox = $this->mailbox();

        $res = $this->upload($mailbox)->assertOk();

        $mailbox->refresh();
        $this->assertNotNull($mailbox->avatar_path);
        $this->assertNotNull($mailbox->avatar_token);
        Storage::disk('local')->assertExists($mailbox->avatar_path);

        [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get($mailbox->avatar_path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$w, $h, $type]);

        $url = $res->json('mailbox.avatar_url');
        $this->assertStringContainsString('/avatars/'.$mailbox->avatar_token.'?v=', $url);
        $this->getJson('/api/mailbox/me')->assertJsonPath('mailbox.avatar_url', $url);
    }

    public function test_public_url_serves_the_photo_and_unknown_tokens_404(): void
    {
        $mailbox = $this->mailbox();
        $this->upload($mailbox)->assertOk();
        $mailbox->refresh();

        $res = $this->get('/avatars/'.$mailbox->avatar_token);
        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));

        $this->get('/avatars/doesnotexist123')->assertNotFound();
    }

    public function test_replacing_and_removing_delete_the_old_file(): void
    {
        $mailbox = $this->mailbox();
        $this->upload($mailbox)->assertOk();
        $first = $mailbox->refresh()->avatar_path;
        $token = $mailbox->avatar_token;

        $this->upload($mailbox, 300, 300)->assertOk();
        $mailbox->refresh();
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($mailbox->avatar_path);
        $this->assertSame($token, $mailbox->avatar_token); // stable URL for already-sent mail

        $mailbox->update(['avatar_in_signature' => true]);
        $second = $mailbox->avatar_path;

        $this->deleteJson('/api/mailbox/me/avatar')
            ->assertOk()
            ->assertJsonPath('mailbox.avatar_url', null)
            ->assertJsonPath('mailbox.avatar_in_signature', false);
        Storage::disk('local')->assertMissing($second);
        $this->get('/avatars/'.$token)->assertNotFound();
    }

    public function test_deleting_the_mailbox_deletes_its_photo(): void
    {
        $mailbox = $this->mailbox();
        $this->upload($mailbox)->assertOk();
        $path = $mailbox->refresh()->avatar_path;

        $mailbox->delete();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_non_images_are_rejected(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->post('/api/mailbox/me/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('me.png', 'not really a png'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNull($mailbox->refresh()->avatar_path);
    }

    public function test_photo_in_signature_preference_is_saved(): void
    {
        $mailbox = $this->mailbox();
        Sanctum::actingAs($mailbox);

        $this->putJson('/api/mailbox/me', ['display_name' => 'Ada', 'avatar_in_signature' => true])
            ->assertOk()
            ->assertJsonPath('mailbox.avatar_in_signature', true);
    }

    private function ingest(CloudflareAccount $account, array $headers, string $from = 'ada@a.com'): Email
    {
        (new StoreIncomingEmail($account->id, [
            'message_id' => '<m'.uniqid().'@a.com>',
            'envelope_from' => 'bounce@cf-bounce.a.com',
            'envelope_to' => 'me@a.com',
            'from' => ['address' => $from, 'name' => 'Ada'],
            'subject' => 'Hi',
            'text' => 'Hello',
            'headers' => $headers,
        ]))->handle();

        return Email::latest('id')->first();
    }

    public function test_verified_internal_sender_shows_their_photo_in_the_inbox(): void
    {
        $me = $this->mailbox();
        $ada = $this->mailbox('ada@a.com');
        $this->upload($ada)->assertOk();
        $ada->refresh();

        $email = $this->ingest($me->account, [['authentication-results', self::CF_PASS], ['subject', 'Hi']], 'Ada@A.com');
        $this->assertSame($ada->id, $email->from_mailbox_id);

        Sanctum::actingAs($me);
        $this->getJson('/api/mailbox/emails')->assertJsonPath('data.0.from_avatar_url', $ada->avatarUrl());
        $this->getJson("/api/mailbox/emails/{$email->id}")->assertJsonPath('email.from_avatar_url', $ada->avatarUrl());
        $this->getJson("/api/mailbox/emails/{$email->id}/thread")->assertJsonPath('messages.0.from_avatar_url', $ada->avatarUrl());
    }

    public function test_unverified_or_spoofed_senders_get_no_photo(): void
    {
        $me = $this->mailbox();
        $this->mailbox('ada@a.com');

        $cases = [
            'no auth header' => [],
            'dmarc failed' => [['authentication-results', 'mx.cloudflare.net; spf=pass smtp.mailfrom=evil.com; dmarc=fail header.from=a.com']],
            'not cloudflare' => [['authentication-results', 'mx.evil.com; dmarc=pass header.from=a.com']],
            // A sender-supplied header after Cloudflare's (Workers join repeats with ", ").
            'forged second header' => [['authentication-results', 'mx.cloudflare.net; dmarc=fail header.from=a.com, mx.cloudflare.net; dmarc=pass']],
            // A fake verdict smuggled in a quoted MAIL FROM local part.
            'quoted injection' => [['authentication-results', 'mx.cloudflare.net; spf=pass smtp.mailfrom="x;dmarc=pass"@evil.com; dmarc=fail']],
            'other domain verified' => [['authentication-results', 'mx.cloudflare.net; dmarc=pass header.from=evil.com']],
        ];

        foreach ($cases as $label => $headers) {
            $email = $this->ingest($me->account, $headers);
            $this->assertNull($email->from_mailbox_id, $label);
        }
    }

    public function test_own_sent_messages_carry_the_own_photo(): void
    {
        $me = $this->mailbox();
        $this->upload($me)->assertOk();
        $me->refresh();

        $sent = $me->sentEmails()->create([
            'cloudflare_account_id' => $me->cloudflare_account_id,
            'driver' => 'api', 'from_email' => $me->email, 'to' => ['x@y.com'],
            'subject' => 'Out', 'status' => 'delivered', 'sent_at' => now(),
        ]);

        $this->getJson('/api/mailbox/sent')->assertJsonPath('data.0.from_avatar_url', $me->avatarUrl());
        $this->getJson("/api/mailbox/sent/{$sent->id}")->assertJsonPath('email.from_avatar_url', $me->avatarUrl());
    }
}
