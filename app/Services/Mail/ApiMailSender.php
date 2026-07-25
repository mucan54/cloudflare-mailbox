<?php

namespace App\Services\Mail;

use App\Models\CloudflareAccount;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareException;

/**
 * Sends through the Cloudflare Email Sending REST API. Returns a synchronous
 * delivered/queued/bounced result.
 */
class ApiMailSender implements MailSender
{
    public function send(CloudflareAccount $account, array $message): SendResult
    {
        try {
            $result = CloudflareClient::forAccount($account)->send($this->toApiPayload($message));
        } catch (CloudflareException $e) {
            return SendResult::failed($this->explain($e), ['errors' => $e->errors]);
        }

        if (! empty($result['permanent_bounces'])) {
            return SendResult::bounced($result, 'Recipient permanently bounced');
        }

        if (! empty($result['delivered'])) {
            return SendResult::delivered($result);
        }

        if (! empty($result['queued'])) {
            return SendResult::queued($result);
        }

        // Cloudflare returned success but accepted NO recipient (all buckets
        // empty). The message was not sent — the recipient is almost always on
        // the account's Suppressions list from a prior bounce/complaint. Do not
        // mislabel this as "queued".
        return SendResult::failed(
            'Cloudflare hiçbir alıcıyı kabul etmedi — alıcı büyük ihtimalle Suppressions '
            .'listesinde (önceki bounce/şikâyet). Cloudflare → Email Service → Email Sending → '
            .'Suppressions’ı kontrol edip adresi kaldırın, ya da farklı bir alıcıyla deneyin.',
            $result,
        );
    }

    /**
     * Turn a raw Cloudflare send error into an actionable message.
     */
    protected function explain(CloudflareException $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'sending_disabled')) {
            return $msg.' — Bu domain Cloudflare’de Email Sending için onboard edilmemiş '
                .'(ya da yalnızca doğrulanmış hedeflere gönderime açık). Çözüm: Cloudflare Dashboard → '
                .'Compute → Email Service → Email Sending → “Onboard Domain” ile domaini ekleyin.';
        }

        if (str_contains($msg, 'sender') && str_contains($msg, 'verif')) {
            return $msg.' — Gönderen domain doğrulanmamış. Email Sending onboarding’ini (DKIM dahil) tamamlayın.';
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    protected function toApiPayload(array $message): array
    {
        $payload = array_filter([
            'from' => $message['from'] ?? null,
            'to' => $message['to'] ?? null,
            'cc' => $message['cc'] ?? null,
            'bcc' => $message['bcc'] ?? null,
            'reply_to' => $message['reply_to'] ?? null,
            'subject' => $message['subject'] ?? null,
            'html' => $message['html'] ?? null,
            'text' => $message['text'] ?? null,
            'headers' => $message['headers'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        if (! empty($message['attachments'])) {
            $payload['attachments'] = array_map(function (array $a) {
                return array_filter([
                    'content' => isset($a['content_raw'])
                        ? base64_encode($a['content_raw'])
                        : ($a['content'] ?? null),
                    'filename' => $a['filename'] ?? null,
                    'type' => $a['type'] ?? $a['mime_type'] ?? 'application/octet-stream',
                    'disposition' => $a['disposition'] ?? 'attachment',
                ], fn ($v) => $v !== null);
            }, $message['attachments']);
        }

        return $payload;
    }
}
