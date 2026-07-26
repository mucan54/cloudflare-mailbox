<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sends a web-push reminder ~30 minutes before a timed event. Idempotent via
 * events.reminded_at, so running it every few minutes never double-notifies.
 * Schedule it every 5 minutes (see routes/console.php).
 */
class CalendarRemindCommand extends Command
{
    protected $signature = 'calendar:remind';

    protected $description = 'Push a reminder ~30 minutes before upcoming events';

    public function handle(): int
    {
        $now = Carbon::now();
        $window = $now->clone()->addMinutes(30);

        $events = Event::query()
            ->whereNull('reminded_at')
            ->where('all_day', false)
            ->whereNotNull('mailbox_id')
            ->whereBetween('starts_at', [$now, $window])
            ->with('mailbox')
            ->get();

        $sent = 0;
        foreach ($events as $event) {
            $mailbox = $event->mailbox;
            if ($mailbox && $mailbox->pushSubscriptions()->exists()) {
                try {
                    $mailbox->notify(new EventReminderNotification($event));
                    $sent++;
                } catch (Throwable $e) {
                    $this->warn("Event {$event->id}: {$e->getMessage()}");
                }
            }
            // Stamp regardless so we don't retry a subscription-less mailbox forever.
            $event->forceFill(['reminded_at' => $now])->save();
        }

        $this->info("Reminded {$sent} event(s).");

        return self::SUCCESS;
    }
}
