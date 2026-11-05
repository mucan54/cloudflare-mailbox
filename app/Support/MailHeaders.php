<?php

namespace App\Support;

/**
 * Reads the raw headers stored on emails.headers.
 *
 * The Email Worker stores them as a list of [name, value] pairs (Workers'
 * Headers iteration: lowercase names, repeated headers joined with ", ");
 * tests and older rows may use a name => value (or name => [values]) map.
 */
class MailHeaders
{
    /** The value of the first header called $name, if any. */
    public static function first(mixed $headers, string $name): ?string
    {
        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $entry) {
            if (is_string($key)) {
                [$header, $value] = [$key, $entry];  // [name => value | [values]]
            } elseif (is_array($entry) && array_is_list($entry) && count($entry) === 2) {
                [$header, $value] = $entry;          // [[name, value], ...] (the Worker's shape)
            } else {
                continue;
            }

            if (is_string($header) && strcasecmp($header, $name) === 0) {
                $value = is_array($value) ? reset($value) : $value;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * The (first) mailbox address in an address header value such as
     * `"Ada Lovelace" <ada@acme.com>`, `=?UTF-8?B?…?= <ada@acme.com>` or a
     * bare `ada@acme.com`. Null when there is none.
     */
    public static function address(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Display names may contain anything (even "@"), so prefer <angle-addr>.
        if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $value, $m)) {
            return $m[1];
        }

        $bare = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\([^()]*\)/', ' ', $value) ?? $value;
        if (preg_match('/[^\s<>"(),;:]+@[^\s<>"(),;:]+/', $bare, $m)) {
            return $m[0];
        }

        return null;
    }
}
