<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Calendar\IcsWriter;
use Illuminate\Http\Response;

/**
 * Read-only iCalendar feed a user can subscribe to from Apple / Google
 * Calendar. Authenticated by the unguessable per-mailbox token in the URL.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(string $token, IcsWriter $writer): Response
    {
        $mailbox = Mailbox::where('calendar_token', $token)->firstOrFail();

        return response($writer->forMailbox($mailbox), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendar.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
