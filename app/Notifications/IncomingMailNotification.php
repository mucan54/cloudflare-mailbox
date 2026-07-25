<?php

namespace App\Notifications;

use App\Models\Email;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Web Push "you have new mail" — delivered even when the browser is closed
 * (for installed PWAs). Targets the recipient Mailbox.
 *
 * Deliberately NOT ShouldQueue: it is dispatched from the already-queued
 * StoreIncomingEmail job, so sending it inline avoids a second queue hop that
 * could be dropped if the worker is unreliable.
 */
class IncomingMailNotification extends Notification
{
    public function __construct(public Email $email) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $from = $this->email->from_name ?: $this->email->from_email;

        // Deep-link straight to the message in the right account so a tap on the
        // notification opens it. EmailView needs `acc` (which mailbox's token to
        // fetch with) and `type`; the notification is for this mailbox.
        $url = '/mail/'.$this->email->id
            .'?acc='.urlencode((string) $notifiable->email)
            .'&type=received';

        return (new WebPushMessage)
            ->title($from ?: 'Yeni mail')
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge.png')
            ->body(Str::limit($this->email->subject ?: '(konu yok)', 80))
            ->tag('mail-'.$this->email->id)
            ->data([
                'url' => $url,
                'email_id' => $this->email->id,
                // Drives the app-icon badge when the push arrives with the app
                // closed (Badging API, set from the service worker).
                'unread' => $notifiable->emails()->whereNull('read_at')->count(),
            ])
            ->options(['TTL' => 3600]);
    }
}
