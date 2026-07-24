<?php

namespace App\Services\Mail;

/**
 * Normalized outcome of an outbound send across drivers.
 */
class SendResult
{
    /**
     * @param  'delivered'|'queued'|'bounced'|'failed'  $status
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $status,
        public array $raw = [],
        public ?string $error = null,
    ) {}

    public static function delivered(array $raw = []): self
    {
        return new self('delivered', $raw);
    }

    public static function queued(array $raw = []): self
    {
        return new self('queued', $raw);
    }

    public static function bounced(array $raw = [], ?string $error = null): self
    {
        return new self('bounced', $raw, $error);
    }

    public static function failed(string $error, array $raw = []): self
    {
        return new self('failed', $raw, $error);
    }
}
