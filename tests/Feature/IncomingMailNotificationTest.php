<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Notifications\IncomingMailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingMailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webpush_deeplinks_to_message_in_the_right_account(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);
        $mailbox = $account->mailboxes()->create([
            'domain_id' => $domain->id, 'email' => 'me@a.com', 'password' => 'password123', 'login_enabled' => true,
        ]);
        $email = $mailbox->emails()->create([
            'cloudflare_account_id' => $account->id, 'ingest_key' => 'k1', 'subject' => 'Hi', 'received_at' => now(),
        ]);

        $notification = new IncomingMailNotification($email);
        $payload = $notification->toWebPush($mailbox, $notification)->toArray();

        $this->assertSame(
            '/mail/'.$email->id.'?acc=me%40a.com&type=received',
            $payload['data']['url'],
        );
        $this->assertSame($email->id, $payload['data']['email_id']);
    }
}
