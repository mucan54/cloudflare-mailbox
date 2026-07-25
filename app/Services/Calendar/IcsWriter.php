<?php

namespace App\Services\Calendar;

use App\Models\Mailbox;
use Illuminate\Support\Carbon;

/**
 * Renders a mailbox's events as a subscribable iCalendar feed. Each event
 * carries a 30-minute VALARM so a calendar app the user subscribes with
 * (Apple / Google) fires the reminder natively.
 */
class IcsWriter
{
    public function forMailbox(Mailbox $mailbox): string
    {
        $host = str_contains((string) $mailbox->email, '@') ? substr(strrchr($mailbox->email, '@'), 1) : 'mailbox';
        $now = Carbon::now('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Cloudflare Mailbox//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->esc($mailbox->display_name ?: $mailbox->email),
        ];

        foreach ($mailbox->events()->orderBy('starts_at')->get() as $event) {
            $uid = $event->source_uid ?: ('event-'.$event->id.'@'.$host);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$this->esc($uid);
            $lines[] = 'DTSTAMP:'.$now;
            if ($event->all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$event->starts_at->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.($event->ends_at ?: $event->starts_at)->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$event->starts_at->clone()->utc()->format('Ymd\THis\Z');
                $end = $event->ends_at ?: $event->starts_at->clone()->addHour();
                $lines[] = 'DTEND:'.$end->clone()->utc()->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.$this->esc($event->title);
            if ($event->location) {
                $lines[] = 'LOCATION:'.$this->esc($event->location);
            }
            if ($event->notes) {
                $lines[] = 'DESCRIPTION:'.$this->esc($event->notes);
            }
            // 30-minute reminder, honoured by the subscribing calendar app.
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->esc($event->title);
            $lines[] = 'TRIGGER:-PT30M';
            $lines[] = 'END:VALARM';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    protected function esc(string $value): string
    {
        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $value);
    }

    /** Fold lines longer than 75 octets (RFC 5545 §3.1). */
    protected function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        while (strlen($line) > 75) {
            $out .= substr($line, 0, 75)."\r\n ";
            $line = substr($line, 75);
        }

        return $out.$line;
    }
}
