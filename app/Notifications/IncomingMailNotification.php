<?php

namespace App\Notifications;

use App\Models\Email;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Web Push "you have new mail" — delivered even when the browser is closed
 * (for installed PWAs). Targets the recipient Mailbox.
 */
class IncomingMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        return (new WebPushMessage)
            ->title($from ?: 'Yeni mail')
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge.png')
            ->body(Str::limit($this->email->subject ?: '(konu yok)', 80))
            ->tag('mail-'.$this->email->id)
            ->data(['url' => '/mail/'.$this->email->id, 'email_id' => $this->email->id])
            ->options(['TTL' => 3600]);
    }
}
