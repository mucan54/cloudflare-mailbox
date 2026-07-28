<?php

namespace App\Dav;

use App\Models\Event;
use App\Models\Mailbox;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * CalDAV backend mapping a mailbox's single calendar to the events table.
 * Calendar id = mailbox id. Object uri = the client's chosen name (stored in
 * dav_uri) or event-<id>.ics.
 */
class CalendarBackend extends AbstractBackend
{
    private function mailboxFromPrincipal(string $principalUri): ?Mailbox
    {
        return Mailbox::where('email', basename($principalUri))->first();
    }

    public function getCalendarsForUser($principalUri): array
    {
        $mailbox = $this->mailboxFromPrincipal($principalUri);
        if (! $mailbox) {
            return [];
        }

        return [[
            'id' => $mailbox->id,
            'uri' => 'default',
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $mailbox->display_name ? $mailbox->display_name.' — Takvim' : 'Takvim',
            '{'.Plugin::NS_CALDAV.'}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
            '{http://apple.com/ns/ical/}calendar-color' => '#0f6cbd',
            '{http://sabredav.org/ns}sync-token' => (string) (Event::where('mailbox_id', $mailbox->id)->max('updated_at') ?? '0'),
        ]];
    }

    public function createCalendar($principalUri, $calendarUri, array $properties): void {}

    public function deleteCalendar($calendarId): void {}

    public function getCalendarObjects($calendarId): array
    {
        return Event::where('mailbox_id', $calendarId)->get()
            ->map(fn (Event $e) => $this->objectInfo($e, false))
            ->all();
    }

    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        $event = $this->find($calendarId, $objectUri);

        return $event ? $this->objectInfo($event, true) : null;
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        $event = new Event(['mailbox_id' => $calendarId, 'dav_uri' => $objectUri]);
        $this->apply($event, $calendarData);
        $event->save();

        return '"'.md5($calendarData).'"';
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData): ?string
    {
        $event = $this->find($calendarId, $objectUri);
        if (! $event) {
            return null;
        }
        $this->apply($event, $calendarData);
        $event->save();

        return '"'.md5($calendarData).'"';
    }

    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        $this->find($calendarId, $objectUri)?->delete();
    }

    // ---- helpers ----

    private function find(int $calendarId, string $uri): ?Event
    {
        $q = Event::where('mailbox_id', $calendarId);
        if (preg_match('/event-(\d+)\.ics$/', $uri, $m)) {
            return $q->where('id', (int) $m[1])->first() ?? $q->where('dav_uri', $uri)->first();
        }

        return $q->where('dav_uri', $uri)->first();
    }

    private function objectInfo(Event $e, bool $withData): array
    {
        $ics = $this->toIcs($e);

        $info = [
            'id' => $e->id,
            'uri' => $e->dav_uri ?: ('event-'.$e->id.'.ics'),
            'lastmodified' => $e->updated_at?->getTimestamp(),
            'etag' => '"'.md5($ics).'"',
            'size' => strlen($ics),
            'component' => 'vevent',
        ];
        if ($withData) {
            $info['calendardata'] = $ics;
        }

        return $info;
    }

    private function toIcs(Event $e): string
    {
        $vcal = new VCalendar;
        $vevent = $vcal->add('VEVENT', [
            'UID' => $e->source_uid ?: ('event-'.$e->id.'@mailbox'),
            'SUMMARY' => $e->title,
        ]);
        if ($e->location) {
            $vevent->add('LOCATION', $e->location);
        }
        if ($e->notes) {
            $vevent->add('DESCRIPTION', $e->notes);
        }
        if ($e->all_day) {
            $vevent->add('DTSTART', $e->starts_at, ['VALUE' => 'DATE']);
            $vevent->add('DTEND', ($e->ends_at ?: $e->starts_at)->copy()->addDay(), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $e->starts_at);
            $vevent->add('DTEND', $e->ends_at ?: $e->starts_at->copy()->addHour());
        }

        return $vcal->serialize();
    }

    private function apply(Event $event, string $calendarData): void
    {
        $vobj = Reader::read($calendarData);
        $vevent = $vobj->VEVENT;

        $event->title = (string) ($vevent->SUMMARY ?? '(başlıksız)');
        $event->location = isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null;
        $event->notes = isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null;
        $event->source_uid = isset($vevent->UID) ? (string) $vevent->UID : $event->source_uid;

        $start = $vevent->DTSTART;
        $event->all_day = ! $start->hasTime();
        $event->starts_at = $start->getDateTime();
        $event->ends_at = isset($vevent->DTEND) ? $vevent->DTEND->getDateTime() : null;
    }
}
