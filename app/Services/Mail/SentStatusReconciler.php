<?php

namespace App\Services\Mail;

use App\Models\CloudflareAccount;
use App\Models\SentEmail;
use App\Services\Cloudflare\CloudflareClient;

/**
 * The send API records whatever Cloudflare returns immediately. A message it
 * accepts but does not finish in that request stays `queued`, even after the
 * activity log has a final `sent` or `delivered`. This copies that final
 * status back onto the row.
 */
class SentStatusReconciler
{
    /**
     * @return int number of rows updated
     */
    public function reconcile(CloudflareAccount $account): int
    {
        $pending = $account->sentEmails()
            ->where('status', 'queued')
            ->where('sent_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->get();

        $byMessageId = [];
        foreach ($pending as $email) {
            $messageId = $email->cf_response['message_id'] ?? null;
            if (is_string($messageId) && $messageId !== '') {
                $byMessageId[$messageId] = $email;
            }
        }

        if ($byMessageId === []) {
            return 0;
        }

        $client = CloudflareClient::forAccount($account);
        $updated = 0;

        foreach (array_chunk($byMessageId, 20, true) as $chunk) {
            $since = collect($chunk)->min(fn (SentEmail $email) => $email->sent_at)?->copy()->subHour()
                ?? now()->subDays(30);
            if ($since->lt(now()->subDays(30))) {
                $since = now()->subDays(30);
            }

            $events = $client->finalSendingEvents(
                array_keys($chunk),
                $since->utc()->toIso8601String(),
                now()->utc()->toIso8601String(),
            );

            foreach ($events as $event) {
                $email = $chunk[$event['messageId'] ?? ''] ?? null;
                $status = $this->mapStatus((string) ($event['status'] ?? ''));
                if (! $email || $status === null) {
                    continue;
                }

                $email->status = $status;
                if (in_array($status, ['bounced', 'failed'], true)) {
                    $detail = trim((string) (($event['errorDetail'] ?? '') ?: ($event['errorCause'] ?? '')));
                    if ($detail !== '') {
                        $email->error = $detail;
                    }
                }
                $email->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Keep Cloudflare's own success words. Failure names from the activity log
     * fold into the statuses this app already stores.
     */
    protected function mapStatus(string $status): ?string
    {
        return match (strtolower($status)) {
            'delivered' => 'delivered',
            'sent' => 'sent',
            'deliveryfailed', 'bounced' => 'bounced',
            'rejected', 'failed' => 'failed',
            default => null,
        };
    }
}
