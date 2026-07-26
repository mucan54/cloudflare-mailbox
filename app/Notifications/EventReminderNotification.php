<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your event starts soon" web push, ~30 minutes before an event. Not queued —
 * sent inline from the calendar:remind command.
 */
class EventReminderNotification extends Notification
{
    public function __construct(public Event $event) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $when = $this->event->starts_at->clone()->timezone(config('app.timezone', 'UTC'))->format('H:i');
        $body = $this->event->location
            ? $when.' · '.$this->event->location
            : $when;

        return (new WebPushMessage)
            ->title('⏰ '.$this->event->title)
            ->body($body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('event-'.$this->event->id)
            ->data(['url' => '/calendar'])
            ->options(['TTL' => 1800]);
    }
}
