<?php

namespace App\Services\Cloudflare;

/**
 * HMAC signature for the inbound Email Worker -> Laravel webhook.
 * The signed string is `timestamp + "." + body` so a captured request cannot
 * be replayed with a different timestamp.
 */
class WebhookSignature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(
        string $secret,
        string $timestamp,
        string $body,
        string $signature,
        int $toleranceSeconds = 300,
    ): bool {
        if (! ctype_digit((string) $timestamp)) {
            return false;
        }

        // Worker sends milliseconds (Date.now()); accept seconds too.
        $ts = (int) $timestamp;
        $seconds = $ts > 1_000_000_000_000 ? intdiv($ts, 1000) : $ts;

        if (abs(time() - $seconds) > $toleranceSeconds) {
            return false;
        }

        $expected = self::sign($secret, $timestamp, $body);

        return hash_equals($expected, $signature);
    }
}
