<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds a clean one-line preview for a message: the new text only, with the
 * quoted history and its separator line ("________", "----- Original -----",
 * "On … wrote:", "… tarihinde … yazdı:") cut off, so the inbox list never shows
 * a row of underscores or a quoted header block.
 */
class Snippet
{
    public static function make(?string $text, ?string $html = null): string
    {
        $source = ($text !== null && trim($text) !== '') ? $text : (string) $html;
        $raw = trim(strip_tags($source));

        // Markers that begin the quoted history. All are anchored to a line
        // start, so cutting at the byte offset is always on a newline boundary
        // (safe for UTF-8).
        $markers = [
            '/^[ \t]*_{4,}[ \t]*$/m',                                                   // ________ separator line
            '/^[ \t]*-{2,}[ \t]*(original message|orijinal ileti|forwarded message|iletilen ileti|weitergeleitete nachricht)/im',
            '/^[ \t]*(on|le)\b.{0,120}\b(wrote|a écrit)[ \t]*:[ \t]*$/im',
            '/^.{0,120}tarihinde.{0,80}yazd/im',                                        // "… tarihinde … yazdı:"
            '/^[ \t]*>{1,}/m',                                                          // quoted lines
        ];
        $cut = strlen($raw);
        foreach ($markers as $re) {
            if (preg_match($re, $raw, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $cut) {
                $cut = $m[0][1];
            }
        }

        $lead = trim(substr($raw, 0, $cut));
        if ($lead === '') {
            $lead = $raw; // a pure-quote message — better a preview than nothing
        }

        // Nuke any residual separator runs, then collapse whitespace.
        $lead = preg_replace('/_{3,}|-{4,}|={4,}/', ' ', $lead);
        $lead = trim(preg_replace('/\s+/', ' ', (string) $lead));

        return Str::limit($lead, 140);
    }
}
