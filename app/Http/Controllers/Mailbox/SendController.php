<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\SentEmail;
use App\Services\Mail\EmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SendController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->sentEmails()->latest('sent_at');

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('text_body', 'like', "%{$search}%");
            });
        }

        $sent = $query->paginate(min((int) $request->integer('per_page', 30), 100));

        $sent->getCollection()->transform(fn (SentEmail $s) => $this->summary($s));

        return response()->json($sent);
    }

    public function show(Request $request, int $sent): JsonResponse
    {
        $model = $request->user()->sentEmails()->with('attachments')->findOrFail($sent);

        return response()->json(['email' => array_merge($this->summary($model), [
            'cc' => $model->cc,
            'bcc' => $model->bcc,
            'html_body' => $model->html_body,
            'text_body' => $model->text_body,
            'error' => $model->error,
            'attachments' => $model->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'url' => $a->temporaryUrl(),
            ]),
        ])]);
    }

    private function summary(SentEmail $s): array
    {
        return [
            'id' => $s->id,
            'from_email' => $s->from_email,
            'to' => $s->to,
            'to_email' => implode(', ', (array) $s->to),
            'subject' => $s->subject,
            'snippet' => Str::limit(strip_tags((string) ($s->text_body ?: $s->html_body)), 140),
            'status' => $s->status,
            'sent_at' => $s->sent_at?->toIso8601String(),
            'received_at' => $s->sent_at?->toIso8601String(),
        ];
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
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.filename' => ['required', 'string', 'max:255'],
            'attachments.*.type' => ['nullable', 'string', 'max:150'],
            'attachments.*.content' => ['required', 'string'], // base64-encoded bytes
            'attachments.*.size' => ['nullable', 'integer'],
        ]);

        // The mailbox may only send as itself.
        $sent = $sender->send($mailbox->account, [
            'from' => $mailbox->email,
            'from_name' => $mailbox->display_name,
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'],
            'html' => $data['html'] ?? null,
            'text' => $data['text'] ?? null,
            'in_reply_to_email_id' => $data['in_reply_to_email_id'] ?? null,
            'attachments' => $data['attachments'] ?? [],
        ], $mailbox);

        return response()->json([
            'id' => $sent->id,
            'status' => $sent->status,
        ], 201);
    }
}
