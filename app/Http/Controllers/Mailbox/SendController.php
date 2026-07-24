<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\SentEmail;
use App\Services\Mail\EmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sent = $request->user()->sentEmails()->latest('sent_at')->paginate(25);

        $sent->getCollection()->transform(fn (SentEmail $s) => [
            'id' => $s->id,
            'to' => $s->to,
            'subject' => $s->subject,
            'status' => $s->status,
            'sent_at' => $s->sent_at?->toIso8601String(),
        ]);

        return response()->json($sent);
    }

    public function store(Request $request, EmailSender $sender): JsonResponse
    {
        $mailbox = $request->user();

        $data = $request->validate([
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['required', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'in_reply_to_email_id' => ['nullable', 'integer'],
        ]);

        // The mailbox may only send as itself.
        $sent = $sender->send($mailbox->account, [
            'from' => $mailbox->email,
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'],
            'html' => $data['html'] ?? null,
            'text' => $data['text'] ?? null,
            'in_reply_to_email_id' => $data['in_reply_to_email_id'] ?? null,
        ], $mailbox);

        return response()->json([
            'id' => $sent->id,
            'status' => $sent->status,
        ], 201);
    }
}
