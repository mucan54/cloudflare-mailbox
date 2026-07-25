<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
