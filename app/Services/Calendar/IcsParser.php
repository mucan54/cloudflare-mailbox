<?php

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Minimal iCalendar (RFC 5545) reader — enough to turn a meeting-invite .ics
 * into calendar events. Handles line unfolding, VEVENT blocks, TZID/UTC/all-day
 * DTSTART/DTEND and text unescaping. Not a full implementation (no RRULE
 * expansion); unknown pieces are ignored rather than failing.
 */
class IcsParser
{
    /**
     * @return array<int, array{uid: ?string, title: string, location: ?string, notes: ?string, starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}>
     */
    public function parse(string $ics): array
    {
        $lines = $this->unfold($ics);

        $events = [];
        $cur = null;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === 'BEGIN:VEVENT') {
                $cur = [];

                continue;
            }
            if ($trim === 'END:VEVENT') {
                if ($cur !== null) {
                    $event = $this->buildEvent($cur);
                    if ($event) {
                        $events[] = $event;
                    }
                }
                $cur = null;

                continue;
            }
            if ($cur === null) {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $rawName = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            $parts = explode(';', $rawName);
            $name = strtoupper($parts[0]);
            $params = [];
            foreach (array_slice($parts, 1) as $p) {
                if (str_contains($p, '=')) {
                    [$k, $v] = explode('=', $p, 2);
                    $params[strtoupper($k)] = $v;
                }
            }

            $cur[$name] = ['value' => $value, 'params' => $params];
        }

        return $events;
    }

    /**
     * @param  array<string, array{value: string, params: array<string, string>}>  $props
     * @return array{uid: ?string, title: string, location: ?string, notes: ?string, starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    protected function buildEvent(array $props): ?array
    {
        if (! isset($props['DTSTART'])) {
            return null;
        }

        $start = $this->parseDate($props['DTSTART']);
        if (! $start) {
            return null;
        }

        $allDay = ($props['DTSTART']['params']['VALUE'] ?? '') === 'DATE';
        $end = isset($props['DTEND']) ? $this->parseDate($props['DTEND']) : null;

        return [
            'uid' => isset($props['UID']) ? $this->text($props['UID']['value']) : null,
            'title' => isset($props['SUMMARY']) ? ($this->text($props['SUMMARY']['value']) ?: '(başlıksız)') : '(başlıksız)',
            'location' => isset($props['LOCATION']) ? $this->text($props['LOCATION']['value']) : null,
            'notes' => isset($props['DESCRIPTION']) ? $this->text($props['DESCRIPTION']['value']) : null,
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => $allDay,
        ];
    }

    /**
     * @param  array{value: string, params: array<string, string>}  $prop
     */
    protected function parseDate(array $prop): ?Carbon
    {
        $value = trim($prop['value']);
        $tzid = $prop['params']['TZID'] ?? null;
        $isDate = ($prop['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value);

        try {
            if ($isDate) {
                return Carbon::createFromFormat('Ymd', substr($value, 0, 8))->startOfDay();
            }
            if (str_ends_with($value, 'Z')) {
                return Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC');
            }
            if ($tzid) {
                return Carbon::createFromFormat('Ymd\THis', $value, $tzid)->utc();
            }

            // Floating time — interpret in the app timezone.
            return Carbon::createFromFormat('Ymd\THis', $value, config('app.timezone', 'UTC'))->utc();
        } catch (Throwable) {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Unescape an iCal TEXT value.
     */
    protected function text(string $value): string
    {
        return str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $value,
        );
    }

    /**
     * Unfold folded lines: a line beginning with a space or tab continues the
     * previous one (RFC 5545 §3.1).
     *
     * @return array<int, string>
     */
    protected function unfold(string $ics): array
    {
        $raw = preg_split('/\r\n|\r|\n/', $ics);
        $out = [];
        foreach ($raw as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $out) {
                $out[count($out) - 1] .= substr($line, 1);
            } else {
                $out[] = $line;
            }
        }

        return $out;
    }
}
