<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\SentEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboxController extends Controller
{
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
     * Outlook-style stacked thread. Grouping is by normalized subject
     * (Re:/Fwd:/İlt: prefixes stripped), which matches how mail clients thread
     * by default and works even when messages lack References headers.
     */
    public function thread(Request $request, int $email): JsonResponse
    {
        $user = $request->user();
        $anchor = $user->emails()->findOrFail($email);
        $norm = $this->normalizeSubject($anchor->subject);

        if ($norm === '') {
            // No meaningful subject to thread on — just this one message.
            $anchor->loadMissing('attachments');
            if (! $anchor->read_at) {
                $anchor->update(['read_at' => now()]);
            }

            return response()->json([
                'subject' => $anchor->subject,
                'messages' => [$this->threadItem($anchor)],
            ]);
        }

        // Narrow with a LIKE, then match on the exact normalized subject.
        $received = $user->emails()->with('attachments')
            ->where('folder', '!=', 'trash')
            ->where('subject', 'like', '%'.$norm.'%')
            ->get()
            ->filter(fn (Email $e) => $this->normalizeSubject($e->subject) === $norm);

        // Mark the whole conversation read as it's opened.
        $received->each(function (Email $e) {
            if (! $e->read_at) {
                $e->update(['read_at' => now()]);
            }
        });

        $sent = $user->sentEmails()->with('attachments')
            ->where('subject', 'like', '%'.$norm.'%')
            ->get()
            ->filter(fn (SentEmail $s) => $this->normalizeSubject($s->subject) === $norm);

        $messages = $received->map(fn (Email $e) => $this->threadItem($e))
            ->concat($sent->map(fn (SentEmail $s) => $this->threadItemSent($s, $user->display_name, $user->email)))
            ->sortBy(fn (array $m) => $m['received_at'] ?? '')
            ->values();

        return response()->json([
            'subject' => $anchor->subject,
            'messages' => $messages,
        ]);
    }

    private function normalizeSubject(?string $s): string
    {
        $s = trim((string) $s);
        do {
            $prev = $s;
            // Re:, Fw:, Fwd:, İlt:, Yan:, Ynt:, AW:, SV:, VS:, Antw:, WG:
            $s = preg_replace('/^\s*(re|fwd?|ilt|yan|ynt|aw|sv|vs|antw|wg)\s*:\s*/iu', '', (string) $s);
        } while ($s !== $prev);

        return mb_strtolower(trim($s));
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
            'snippet' => Str::limit(strip_tags((string) ($s->text_body ?: $s->html_body)), 140),
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
            'snippet' => Str::limit(strip_tags((string) ($e->text_body ?: $e->html_body)), 140),
            'read' => $e->read_at !== null,
            'starred' => $e->starred,
            'folder' => $e->folder,
            'received_at' => $e->received_at?->toIso8601String(),
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
