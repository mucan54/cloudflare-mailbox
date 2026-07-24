<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\CloudflareAccount;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Mailbox;
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
