<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\CloudflareAccount;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Mailbox;
use App\Notifications\IncomingMailNotification;
use App\Services\Calendar\IcsParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists one inbound email delivered by the Cloudflare Email Worker.
 * Idempotent via emails.ingest_key so retries never duplicate.
 */
class StoreIncomingEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $accountId,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $account = CloudflareAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $p = $this->payload;
        $toEmail = $this->address($p['envelope_to'] ?? $p['to'] ?? null);
        $messageId = $p['message_id'] ?? null;

        $ingestKey = hash('sha256', $account->id.'|'.($messageId ?? Str::uuid()).'|'.$toEmail);

        if (Email::where('ingest_key', $ingestKey)->exists()) {
            return; // idempotent: already stored
        }

        $domain = $this->resolveDomain($account, $toEmail);
        $mailbox = $this->resolveMailbox($account, $toEmail);

        $email = Email::create([
            'cloudflare_account_id' => $account->id,
            'domain_id' => $domain?->id,
            'mailbox_id' => $mailbox?->id,
            'ingest_key' => $ingestKey,
            'message_id' => $messageId,
            'in_reply_to' => $p['in_reply_to'] ?? null,
            'references' => $this->arr($p['references'] ?? []),
            'from_name' => $this->name($p['from'] ?? null),
            'from_email' => $this->address($p['envelope_from'] ?? $p['from'] ?? null),
            'to_email' => $toEmail,
            'cc' => $this->addresses($p['cc'] ?? []),
            'subject' => $p['subject'] ?? null,
            'text_body' => $p['text'] ?? null,
            'html_body' => $p['html'] ?? null,
            'headers' => $p['headers'] ?? null,
            'raw_size' => $p['raw_size'] ?? null,
            'received_at' => isset($p['date']) ? $this->parseDate($p['date']) : now(),
        ]);

        $this->storeAttachments($email, $p['attachments'] ?? []);

        // Auto-add any calendar invites (.ics) to the mailbox's calendar. Never
        // let a bad invite break email storage.
        if ($mailbox) {
            try {
                $this->importCalendarInvites($mailbox, $p['attachments'] ?? []);
            } catch (\Throwable $e) {
                \Log::warning('ICS import failed', ['mailbox' => $mailbox->id, 'error' => $e->getMessage()]);
            }
        }

        // Web Push "you have new mail" — only when the mailbox has subscriptions.
        // Sent inline (the notification is no longer ShouldQueue) so it rides on
        // this already-queued job instead of a second, easily-dropped queue hop.
        // A push failure must never fail the email-store job, so it's isolated.
        if ($mailbox && $mailbox->pushSubscriptions()->exists()) {
            try {
                $mailbox->notify(new IncomingMailNotification($email));
            } catch (\Throwable $e) {
                \Log::warning('Web push notify failed', ['mailbox' => $mailbox->id, 'error' => $e->getMessage()]);
            }
        }
    }

    protected function resolveDomain(CloudflareAccount $account, ?string $toEmail): ?Domain
    {
        $host = $toEmail && str_contains($toEmail, '@') ? substr(strrchr($toEmail, '@'), 1) : null;

        return $host ? $account->domains()->where('name', $host)->first() : null;
    }

    protected function resolveMailbox(CloudflareAccount $account, ?string $toEmail): ?Mailbox
    {
        return $toEmail ? $account->mailboxes()->where('email', $toEmail)->first() : null;
    }

    /**
     * Turn any .ics / text/calendar attachments into calendar events for the
     * mailbox, de-duplicated by the invite UID.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    protected function importCalendarInvites(Mailbox $mailbox, array $attachments): void
    {
        foreach ($attachments as $a) {
            $mime = strtolower((string) ($a['mimeType'] ?? $a['mime_type'] ?? ''));
            $name = strtolower((string) ($a['filename'] ?? ''));
            $isIcs = str_contains($mime, 'text/calendar') || str_ends_with($name, '.ics');
            if (! $isIcs || empty($a['content'])) {
                continue;
            }

            $raw = base64_decode((string) $a['content'], true);
            if ($raw === false) {
                continue;
            }

            foreach (app(IcsParser::class)->parse($raw) as $ev) {
                if (! $ev['starts_at']) {
                    continue;
                }
                $attrs = [
                    'title' => $ev['title'],
                    'location' => $ev['location'],
                    'notes' => $ev['notes'],
                    'starts_at' => $ev['starts_at'],
                    'ends_at' => $ev['ends_at'],
                    'all_day' => $ev['all_day'],
                ];
                // Dedup on UID when present so a re-sent invite updates in place.
                if ($ev['uid']) {
                    $mailbox->events()->updateOrCreate(['source_uid' => $ev['uid']], $attrs);
                } else {
                    $mailbox->events()->create($attrs);
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    protected function storeAttachments(Email $email, array $attachments): void
    {
        $disk = config('cloudflare.attachments_disk', 'local');

        foreach ($attachments as $a) {
            $path = null;

            if (! empty($a['content'])) {
                $binary = base64_decode($a['content'], true);
                if ($binary !== false) {
                    $path = 'attachments/'.$email->id.'/'.Str::random(8).'-'.($a['filename'] ?? 'file');
                    Storage::disk($disk)->put($path, $binary);
                }
            } elseif (! empty($a['key'])) {
                // Worker uploaded the large attachment straight to R2.
                $path = $a['key'];
            }

            Attachment::create([
                'attachable_type' => Email::class,
                'attachable_id' => $email->id,
                'filename' => $a['filename'] ?? 'attachment',
                'mime_type' => $a['mimeType'] ?? $a['mime_type'] ?? null,
                'size' => $a['size'] ?? 0,
                'storage_disk' => $path ? $disk : null,
                'storage_path' => $path,
                'content_id' => $a['contentId'] ?? $a['content_id'] ?? null,
                'inline' => ! empty($a['contentId']),
            ]);
        }
    }

    protected function address(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value['address'] ?? ($value[0]['address'] ?? null);
        }

        return $value ?: null;
    }

    protected function name(mixed $value): ?string
    {
        return is_array($value) ? ($value['name'] ?? null) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function addresses(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($v) => is_array($v) ? ($v['address'] ?? null) : $v)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function arr(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value)) : [];
    }

    protected function parseDate(mixed $date): mixed
    {
        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return now();
        }
    }
}
