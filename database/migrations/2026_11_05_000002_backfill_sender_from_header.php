<?php

use App\Support\AuthenticationResults;
use App\Support\MailHeaders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix for mail stored before the sender came from the From header.
 *
 * emails.from_email used to hold the envelope sender (MAIL FROM), which for
 * Cloudflare Email Sending and most bulk senders is a bounce address, so the
 * inbox showed it, "Reply" went to it, and it was offered as a recipient
 * suggestion. Re-derive it from the stored raw From header. While here, link
 * DMARC-verified senders on this server (from_mailbox_id) so older mail shows
 * their profile photo too. Idempotent; rows without stored headers are left
 * alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mailboxes = DB::table('mailboxes')->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower((string) $email) => $id]);

        DB::table('emails')
            ->whereNotNull('headers')
            ->select(['id', 'from_email', 'from_mailbox_id', 'headers'])
            ->chunkById(500, function ($rows) use ($mailboxes) {
                foreach ($rows as $row) {
                    $headers = json_decode((string) $row->headers, true);
                    $from = MailHeaders::address(MailHeaders::first($headers, 'from'));
                    if (! $from) {
                        continue;
                    }

                    $changes = [];
                    if (strcasecmp($from, (string) $row->from_email) !== 0) {
                        $changes['from_email'] = $from;
                    }

                    $sender = strtolower($from);
                    if (! $row->from_mailbox_id && isset($mailboxes[$sender])
                        && AuthenticationResults::dmarcPassed($headers, substr(strrchr($sender, '@'), 1))) {
                        $changes['from_mailbox_id'] = $mailboxes[$sender];
                    }

                    if ($changes) {
                        DB::table('emails')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data fix only; the envelope sender isn't kept, so there is nothing to restore.
    }
};
