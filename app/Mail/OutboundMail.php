<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class OutboundMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $message  normalized message
     */
    public function __construct(public array $message) {}

    public function envelope(): Envelope
    {
        $m = $this->message;

        return new Envelope(
            from: new Address($m['from'], $m['from_name'] ?? null),
            to: $this->addresses($m['to'] ?? []),
            cc: $this->addresses($m['cc'] ?? []),
            bcc: $this->addresses($m['bcc'] ?? []),
            replyTo: isset($m['reply_to']) ? [new Address($m['reply_to'])] : [],
            subject: $m['subject'] ?? '',
        );
    }

    public function headers(): Headers
    {
        $h = $this->message['headers'] ?? [];
        $text = [];
        foreach ($h as $name => $value) {
            // messageId + references have dedicated slots; everything else
            // (In-Reply-To, …) rides along as a custom text header.
            if (strcasecmp($name, 'Message-ID') === 0 || strcasecmp($name, 'References') === 0) {
                continue;
            }
            $text[$name] = $value;
        }

        $messageId = null;
        foreach ($h as $name => $value) {
            if (strcasecmp($name, 'Message-ID') === 0) {
                // Headers::messageId wants the id without the angle brackets.
                $messageId = trim((string) $value, '<>');
            }
        }

        $references = [];
        foreach ($h as $name => $value) {
            if (strcasecmp($name, 'References') === 0) {
                $references = preg_split('/\s+/', trim((string) $value)) ?: [];
            }
        }

        return new Headers(
            messageId: $messageId,
            references: $references,
            text: $text,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->message['html'] ?? null,
            text: isset($this->message['text']) && ! isset($this->message['html'])
                ? 'mail.raw-text'
                : null,
            with: ['text' => $this->message['text'] ?? ''],
        );
    }

    public function attachments(): array
    {
        return collect($this->message['attachments'] ?? [])
            ->map(function (array $a) {
                $data = isset($a['content_raw']) ? $a['content_raw'] : base64_decode($a['content'] ?? '');

                return Attachment::fromData(fn () => $data, $a['filename'] ?? 'attachment')
                    ->withMime($a['type'] ?? $a['mime_type'] ?? 'application/octet-stream');
            })
            ->all();
    }

    /**
     * @param  string|array<int, string>  $value
     * @return array<int, Address>
     */
    protected function addresses(string|array $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter()
            ->map(fn (string $email) => new Address($email))
            ->values()
            ->all();
    }
}
