<?php

namespace App\Services\Mail;

use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use App\Models\SentEmail;
use RuntimeException;

/**
 * Orchestrates an outbound send: picks the driver, enforces the 5 MiB limit,
 * dispatches, and records a `sent_emails` row with the resulting status.
 */
class EmailSender
{
    public function driverFor(CloudflareAccount $account): MailSender
    {
        $driver = $account->sending_driver ?: config('cloudflare.sending.driver', 'api');

        return $driver === 'smtp' ? new SmtpMailSender : new ApiMailSender;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function send(CloudflareAccount $account, array $input, ?Mailbox $mailbox = null): SentEmail
    {
        $message = $this->normalize($input);
        $this->assertWithinSizeLimit($message);

        $result = $this->driverFor($account)->send($account, $message);

        return SentEmail::create([
            'cloudflare_account_id' => $account->id,
            'domain_id' => $this->resolveDomainId($account, $message['from']),
            'mailbox_id' => $mailbox?->id,
            'driver' => $account->sending_driver ?: config('cloudflare.sending.driver', 'api'),
            'from_email' => $message['from'],
            'to' => $this->arr($message['to'] ?? []),
            'cc' => $this->arr($message['cc'] ?? []),
            'bcc' => $this->arr($message['bcc'] ?? []),
            'reply_to' => $message['reply_to'] ?? null,
            'subject' => $message['subject'] ?? null,
            'html_body' => $message['html'] ?? null,
            'text_body' => $message['text'] ?? null,
            'status' => $result->status,
            'cf_response' => $result->raw,
            'error' => $result->error,
            'in_reply_to_email_id' => $input['in_reply_to_email_id'] ?? null,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalize(array $input): array
    {
        if (empty($input['from'])) {
            throw new RuntimeException('A "from" address is required.');
        }

        return array_filter([
            'from' => $input['from'],
            'from_name' => $input['from_name'] ?? null,
            'to' => $this->arr($input['to'] ?? []),
            'cc' => $this->arr($input['cc'] ?? []),
            'bcc' => $this->arr($input['bcc'] ?? []),
            'reply_to' => $input['reply_to'] ?? null,
            'subject' => $input['subject'] ?? null,
            'html' => $input['html'] ?? null,
            'text' => $input['text'] ?? null,
            'headers' => $input['headers'] ?? null,
            'attachments' => $input['attachments'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function assertWithinSizeLimit(array $message): void
    {
        $bytes = strlen((string) ($message['html'] ?? '')) + strlen((string) ($message['text'] ?? ''));

        foreach ($message['attachments'] ?? [] as $a) {
            $bytes += isset($a['content_raw'])
                ? strlen($a['content_raw'])
                : (int) (strlen($a['content'] ?? '') * 0.75); // base64 -> bytes estimate
        }

        $max = (int) config('cloudflare.sending.max_bytes', 5 * 1024 * 1024);

        if ($bytes > $max) {
            throw new RuntimeException('Mesaj boyutu 5 MiB sınırını aşıyor.');
        }
    }

    protected function resolveDomainId(CloudflareAccount $account, string $from): ?int
    {
        $domain = str_contains($from, '@') ? substr(strrchr($from, '@'), 1) : null;

        if (! $domain) {
            return null;
        }

        return $account->domains()->where('name', $domain)->value('id');
    }

    /**
     * @param  string|array<int, string>  $value
     * @return array<int, string>
     */
    protected function arr(string|array $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter()
            ->values()
            ->all();
    }
}
