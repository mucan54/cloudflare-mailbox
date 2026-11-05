<?php

namespace Tests\Feature;

use App\Models\CloudflareAccount;
use App\Models\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSenderTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        (require database_path('migrations/2026_11_05_000002_backfill_sender_from_header.php'))->up();
    }

    public function test_old_rows_get_the_from_header_sender_and_verified_photo_link(): void
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $ada = $account->mailboxes()->create(['email' => 'ada@acme.com', 'password' => 'secret']);

        $row = fn (string $key, ?array $headers, string $from) => Email::create([
            'cloudflare_account_id' => $account->id, 'ingest_key' => $key,
            'from_email' => $from, 'headers' => $headers, 'received_at' => now(),
        ]);

        $bounced = $row('k1', [
            ['authentication-results', 'mx.cloudflare.net; spf=pass; dmarc=pass header.from=acme.com'],
            ['from', 'Ada Lovelace <Ada@acme.com>'],
        ], 'bounce-1@cf-bounce.acme.com');
        $unverified = $row('k2', [['from', 'Ada Lovelace <ada@acme.com>']], 'bounce-2@cf-bounce.acme.com');
        $correct = $row('k3', [['from', 'Grace <grace@x.com>']], 'grace@x.com');
        $noHeaders = $row('k4', null, 'robot@x.com');

        $this->runBackfill();
        $this->runBackfill(); // idempotent

        $this->assertSame('Ada@acme.com', $bounced->fresh()->from_email);
        $this->assertSame($ada->id, $bounced->fresh()->from_mailbox_id);

        $this->assertSame('ada@acme.com', $unverified->fresh()->from_email);
        $this->assertNull($unverified->fresh()->from_mailbox_id); // no DMARC pass → no photo

        $this->assertSame('grace@x.com', $correct->fresh()->from_email);
        $this->assertSame('robot@x.com', $noHeaders->fresh()->from_email);
    }
}
