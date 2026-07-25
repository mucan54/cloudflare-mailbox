<?php

namespace Tests\Feature;

use App\Console\Commands\CalendarRemindCommand;
use App\Jobs\StoreIncomingEmail;
use App\Models\CloudflareAccount;
use App\Models\Mailbox;
use App\Notifications\EventReminderNotification;
use App\Services\Calendar\IcsParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(): Mailbox
    {
        $account = CloudflareAccount::create(['name' => 'Acme', 'account_id' => 'acc1', 'api_token' => 'tok']);
        $domain = $account->domains()->create(['zone_id' => 'z1', 'name' => 'a.com']);

        return $account->mailboxes()->create([
            'domain_id' => $domain->id, 'email' => 'me@a.com', 'password' => 'x', 'login_enabled' => true,
        ]);
    }

    private function ics(string $uid = 'uid-1', string $start = '20260805T140000Z'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT',
            'UID:'.$uid, 'SUMMARY:Team Sync', 'LOCATION:Zoom',
            'DTSTART:'.$start, 'DTEND:20260805T150000Z', 'END:VEVENT', 'END:VCALENDAR',
        ]);
    }

    public function test_parser_reads_a_vevent(): void
    {
        $events = (new IcsParser)->parse($this->ics());

        $this->assertCount(1, $events);
        $this->assertSame('Team Sync', $events[0]['title']);
        $this->assertSame('Zoom', $events[0]['location']);
        $this->assertSame('2026-08-05T14:00:00+00:00', $events[0]['starts_at']->toIso8601String());
    }

    public function test_incoming_ics_attachment_creates_an_event(): void
    {
        $mailbox = $this->mailbox();

        StoreIncomingEmail::dispatchSync($mailbox->cloudflare_account_id, [
            'envelope_to' => 'me@a.com',
            'message_id' => 'm1',
            'subject' => 'Invite',
            'attachments' => [
                ['filename' => 'invite.ics', 'mimeType' => 'text/calendar', 'content' => base64_encode($this->ics())],
            ],
        ]);

        $this->assertDatabaseHas('events', [
            'mailbox_id' => $mailbox->id, 'source_uid' => 'uid-1', 'title' => 'Team Sync', 'location' => 'Zoom',
        ]);
    }

    public function test_reimporting_same_uid_updates_not_duplicates(): void
    {
        $mailbox = $this->mailbox();
        foreach (['20260805T140000Z', '20260805T160000Z'] as $i => $start) {
            StoreIncomingEmail::dispatchSync($mailbox->cloudflare_account_id, [
                'envelope_to' => 'me@a.com', 'message_id' => 'm'.$i,
                'attachments' => [['filename' => 'i.ics', 'mimeType' => 'text/calendar', 'content' => base64_encode($this->ics('uid-x', $start))]],
            ]);
        }

        $this->assertSame(1, $mailbox->events()->where('source_uid', 'uid-x')->count());
    }

    public function test_calendar_feed_returns_ics_for_the_token(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->events()->create(['title' => 'Demo', 'starts_at' => Carbon::parse('2026-08-05 14:00:00'), 'ends_at' => Carbon::parse('2026-08-05 15:00:00')]);
        $token = $mailbox->calendarToken();

        $res = $this->get("/calendar/{$token}.ics");

        $res->assertOk();
        $this->assertStringContainsString('text/calendar', $res->headers->get('Content-Type'));
        $res->assertSee('BEGIN:VCALENDAR', false);
        $res->assertSee('SUMMARY:Demo', false);
        $res->assertSee('TRIGGER:-PT30M', false); // 30-min alarm present

        $this->get('/calendar/wrongtoken.ics')->assertNotFound();
    }

    public function test_reminder_command_notifies_upcoming_events_once(): void
    {
        Notification::fake();
        $mailbox = $this->mailbox();
        $mailbox->updatePushSubscription('https://push.example/x', 'k', 't', 'aes128gcm');

        $soon = $mailbox->events()->create(['title' => 'Soon', 'starts_at' => Carbon::now()->addMinutes(20)]);
        $mailbox->events()->create(['title' => 'Later', 'starts_at' => Carbon::now()->addHours(3)]);

        $this->artisan(CalendarRemindCommand::class)->assertSuccessful();

        Notification::assertSentTo($mailbox, EventReminderNotification::class);
        $this->assertNotNull($soon->fresh()->reminded_at);

        // Running again does not notify a second time.
        Notification::fake();
        $this->artisan(CalendarRemindCommand::class)->assertSuccessful();
        Notification::assertNothingSent();
    }
}
