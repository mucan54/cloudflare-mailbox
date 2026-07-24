<?php

namespace App\Services\Mail;

use App\Mail\OutboundMail;
use App\Models\CloudflareAccount;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends through Cloudflare's SMTP endpoint using Laravel's `cloudflare` mailer.
 * The per-tenant API token is injected as the SMTP password at runtime.
 * SMTP has no synchronous delivery status, so acceptance == "queued".
 */
class SmtpMailSender implements MailSender
{
    public function send(CloudflareAccount $account, array $message): SendResult
    {
        Config::set('mail.mailers.cloudflare.password', $account->api_token);
        Mail::purge('cloudflare');

        try {
            Mail::mailer('cloudflare')->send(new OutboundMail($message));
        } catch (Throwable $e) {
            return SendResult::failed($e->getMessage());
        }

        return SendResult::queued(['transport' => 'smtp']);
    }
}
