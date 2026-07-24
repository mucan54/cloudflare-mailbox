<?php

namespace App\Services\Mail;

use App\Models\CloudflareAccount;

interface MailSender
{
    /**
     * @param  array<string, mixed>  $message  normalized message (from,to,cc,bcc,
     *                                         reply_to,subject,html,text,headers,attachments)
     */
    public function send(CloudflareAccount $account, array $message): SendResult;
}
