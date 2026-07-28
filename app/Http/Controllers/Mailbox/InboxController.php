<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\SentEmail;
use App\Support\Snippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

class InboxController extends Controller
{
    /**
     * The full RFC822 message, rebuilt from the stored parts. Used by the
     * optional IMAP bridge (FETCH BODY[]) so native mail apps can render it.
     */
    public function raw(Request $request, int $email): Response
    {
        $e = $request->user()->emails()->with('attachments')->findOrFail($email);

        $from = filter_var($e->from_email, FILTER_VALIDATE_EMAIL) ?: 'unknown@localhost';
        $to = filter_var($e->to_email, FILTER_VALIDATE_EMAIL) ?: $request->user()->email;

        $mime = (new MimeEmail)
            ->from(new Address($from, (string) ($e->from_name ?? '')))
            ->to($to)
            ->subject((string) ($e->subject ?? ''));

        if ($e->received_at) {
            $mime->date($e->received_at);
        }
        foreach ((array) $e->cc as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mime->addCc($cc);
            }
        }
        if ($e->text_body) {
            $mime->text($e->text_body);
        }
        if ($e->html_body) {
            $mime->html($e->html_body);
        }
        foreach ($e->attachments as $a) {
            if ($a->storage_disk && $a->storage_path && Storage::disk($a->storage_disk)->exists($a->storage_path)) {
                $mime->attach(Storage::disk($a->storage_disk)->get($a->storage_path), $a->filename, $a->mime_type);
            }
        }

        return response($mime->toString(), 200, ['Content-Type' => 'message/rfc822']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->emails()->latest('received_at');

        // Folder: inbox (default, excludes trash), starred, or trash.
        $folder = $request->string('folder')->toString() ?: 'inbox';
        match ($folder) {
            'starred' => $query->where('starred', true)->where('folder', '!=', 'trash'),
            'trash' => $query->where('folder', 'trash'),
            default => $query->where('folder', '!=', 'trash'),
        };

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('from_email', 'like', "%{$search}%")
                    ->orWhere('from_name', 'like', "%{$search}%")
                    ->orWhere('text_body', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $emails = $query->paginate(min((int) $request->integer('per_page', 30), 100));

        $emails->getCollection()->transform(fn (Email $e) => $this->summary($e));

        return response()->json($emails);
    }

    public function show(Request $request, int $email): JsonResponse
    {
        $model = $request->user()->emails()->with('attachments')->findOrFail($email);

        if (! $model->read_at) {
            $model->update(['read_at' => now()]);
        }

        return response()->json(['email' => $this->detail($model)]);
    }

    public function update(Request $request, int $email): JsonResponse
    {
        $model = $request->user()->emails()->findOrFail($email);

        $data = $request->validate([
            'read' => ['nullable', 'boolean'],
            'starred' => ['nullable', 'boolean'],
            'folder' => ['nullable', 'string', 'max:50'],
        ]);

        if ($request->has('read')) {
            $data['read_at'] = $data['read'] ? now() : null;
            unset($data['read']);
        }

        $model->update($data);

        return response()->json(['email' => $this->summary($model->fresh())]);
    }

    /**
     * All messages in the same conversation as $email — received + sent,
     * merged and ordered oldest→newest — so the client can render an
     * Outlook-style stacked thread.
     *
     * Threading is by RFC 5322 Message-ID / In-Reply-To / References (the JWZ
     * approach every mail client uses), NOT by subject: a genuinely new mail
     * (no References / In-Reply-To) is its own conversation even if it reuses a
     * subject or comes from the same person. A reply is caught because it
     * carries the original's Message-ID in its In-Reply-To / References.
     */
    public function thread(Request $request, int $email): JsonResponse
    {
        $user = $request->user();
        $anchor = $user->emails()->findOrFail($email);
        $root = $this->threadId($anchor);

        // Match received messages by their computed thread root. Only the
        // lightweight header columns are needed to decide membership.
        $ids = $user->emails()
            ->where('folder', '!=', 'trash')
            ->get(['id', 'message_id', 'in_reply_to', 'references'])
            ->filter(fn (Email $e) => $this->threadId($e) === $root)
            ->pluck('id');

        $received = $user->emails()->with('attachments')->whereIn('id', $ids)->get();

        // Mark the whole conversation read as it's opened.
        $received->each(function (Email $e) {
            if (! $e->read_at) {
                $e->update(['read_at' => now()]);
            }
        });

        // Our own messages in this conversation: match by their thread root
        // (Message-ID/References we now persist) OR, for legacy rows without a
        // Message-ID, by the DB-id link to the parent received message.
        $idList = $ids->all();
        $sentIds = $user->sentEmails()
            ->get(['id', 'message_id', 'in_reply_to', 'references', 'in_reply_to_email_id'])
            ->filter(fn (SentEmail $s) => $this->threadId($s) === $root
                || in_array($s->in_reply_to_email_id, $idList, true))
            ->pluck('id');
        $sent = $user->sentEmails()->with('attachments')->whereIn('id', $sentIds)->get();

        $messages = $received->map(fn (Email $e) => $this->threadItem($e))
            ->concat($sent->map(fn (SentEmail $s) => $this->threadItemSent($s, $user->display_name, $user->email)))
            ->sortBy(fn (array $m) => $m['received_at'] ?? '')
            ->values();

        return response()->json([
            'subject' => $anchor->subject,
            'messages' => $messages,
        ]);
    }

    /**
     * The conversation key for a message (received OR sent): the root
     * Message-ID of its reply chain. References is ordered oldest→newest, so
     * references[0] is the thread origin; otherwise the message it directly
     * replies to; otherwise its own Message-ID. A message with none of these is
     * a thread of one (keyed by its DB id, type-prefixed so a received id never
     * collides with a sent id) — never merged with unrelated mail.
     *
     * @param  Email|SentEmail  $m
     */
    private function threadId($m): string
    {
        $refs = is_array($m->references) ? $m->references : [];
        $root = $refs[0] ?? $m->in_reply_to ?? $m->message_id ?? null;
        $root = is_string($root) ? trim($root) : '';
        if ($root !== '') {
            return $root;
        }

        return ($m instanceof SentEmail ? 'sid:' : 'id:').$m->id;
    }

    /** @return array<string, mixed> */
    private function threadItem(Email $e): array
    {
        return array_merge($this->detail($e), ['type' => 'received', 'mine' => false]);
    }

    /** @return array<string, mixed> */
    private function threadItemSent(SentEmail $s, ?string $fromName, string $fromEmail): array
    {
        return [
            'id' => $s->id,
            'type' => 'sent',
            'mine' => true,
            'from_email' => $s->from_email ?: $fromEmail,
            'from_name' => $fromName,
            'to_email' => is_array($s->to) ? implode(', ', $s->to) : (string) $s->to,
            'cc' => $s->cc,
            'subject' => $s->subject,
            'snippet' => Snippet::make($s->text_body, $s->html_body),
            'html_body' => $s->html_body,
            'text_body' => $s->text_body,
            'read' => true,
            'starred' => false,
            'received_at' => $s->sent_at?->toIso8601String(),
            'attachments' => $s->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
            ]),
        ];
    }

    private function summary(Email $e): array
    {
        return [
            'id' => $e->id,
            'from_email' => $e->from_email,
            'from_name' => $e->from_name,
            'subject' => $e->subject,
            'snippet' => Snippet::make($e->text_body, $e->html_body),
            'read' => $e->read_at !== null,
            'starred' => $e->starred,
            'folder' => $e->folder,
            'received_at' => $e->received_at?->toIso8601String(),
            'thread_id' => $this->threadId($e),
        ];
    }

    private function detail(Email $e): array
    {
        return array_merge($this->summary($e), [
            'to_email' => $e->to_email,
            'cc' => $e->cc,
            'html_body' => $e->html_body,
            'text_body' => $e->text_body,
            'attachments' => $e->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'url' => $a->temporaryUrl(),
            ]),
        ]);
    }
}
