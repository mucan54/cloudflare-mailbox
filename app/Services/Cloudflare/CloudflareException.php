<?php

namespace App\Services\Cloudflare;

use RuntimeException;

class CloudflareException extends RuntimeException
{
    /** @var array<int, array{code?: int, message?: string}> */
    public array $errors = [];

    /**
     * @param  array<int, array{code?: int, message?: string}>  $errors
     */
    public static function fromResponse(string $context, array $errors, int $status = 0): self
    {
        $first = $errors[0]['message'] ?? 'unknown error';
        $e = new self("Cloudflare API error ({$context}): {$first}", $status);
        $e->errors = $errors;

        return $e;
    }
}
