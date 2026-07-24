<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Email;
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
