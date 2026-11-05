<?php

namespace App\Support;

/**
 * Reads the DMARC verdict Cloudflare Email Routing records on inbound mail.
 *
 * Before invoking the Email Worker, Cloudflare prepends an RFC 8601 header:
 *
 *     Authentication-Results: mx.cloudflare.net; spf=pass ...; dkim=pass ...; dmarc=pass ...
 *
 * Only the FIRST Authentication-Results header with the `mx.cloudflare.net`
 * authserv-id is trustworthy — any later one came from the sender. A
 * dmarc=pass there means the domain in the From header is authentic.
 */
class AuthenticationResults
{
    public const AUTHSERV_ID = 'mx.cloudflare.net';

    /**
     * @param  mixed  $headers  as stored on emails.headers: the Worker's list of
     *                          [name, value] pairs, or a name => value map
     */
    public static function dmarcPassed(mixed $headers, ?string $fromDomain = null): bool
    {
        $value = self::first($headers);
        if ($value === null) {
            return false;
        }

        // Drop quoted strings and (nested) comments so sender-controlled text
        // echoed back in them (e.g. a quoted MAIL FROM local part) can't
        // smuggle in a fake "; dmarc=pass".
        $clean = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $value) ?? '';
        do {
            $before = $clean;
            $clean = preg_replace('/\([^()]*\)/', ' ', $clean) ?? '';
        } while ($clean !== $before);

        // The Workers runtime joins repeated headers with ", ". Cloudflare's own
        // header is prepended, so it is the first one.
        $clean = explode(',', $clean, 2)[0];

        $parts = array_map('trim', explode(';', $clean));
        $authserv = strtolower((string) preg_split('/\s+/', array_shift($parts))[0]);
        if ($authserv !== self::AUTHSERV_ID) {
            return false;
        }

        $verdicts = [];
        foreach ($parts as $part) {
            if (! preg_match('/^dmarc\s*=\s*([a-z]+)/i', $part, $m)) {
                continue;
            }
            $ok = strtolower($m[1]) === 'pass';
            if ($ok && $fromDomain && preg_match('/\bheader\.from\s*=\s*([^\s;]+)/i', $part, $d)) {
                $reported = trim($d[1], '"');
                $ok = $reported === '' || strcasecmp($reported, $fromDomain) === 0;
            }
            $verdicts[] = $ok;
        }

        // At least one DMARC result, and every one of them a pass.
        return $verdicts !== [] && ! in_array(false, $verdicts, true);
    }

    /** The value of the first Authentication-Results header, if any. */
    private static function first(mixed $headers): ?string
    {
        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $entry) {
            if (is_array($entry) && array_is_list($entry) && count($entry) === 2 && is_string($entry[0])) {
                [$name, $value] = $entry;          // [[name, value], ...]
            } else {
                [$name, $value] = [$key, $entry];  // [name => value]
            }

            if (is_string($name) && strcasecmp($name, 'authentication-results') === 0) {
                $value = is_array($value) ? reset($value) : $value;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
