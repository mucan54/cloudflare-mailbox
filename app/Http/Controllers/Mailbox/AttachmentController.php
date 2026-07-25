<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Stream an attachment to the signed-in mailbox. Works on any disk (unlike
     * a signed temporary URL, which only some drivers support) and is scoped to
     * the mailbox that owns the parent message.
     */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $mailbox = $request->user();
        $attachable = $attachment->attachable;

        abort_unless($attachable && (int) $attachable->mailbox_id === (int) $mailbox->id, 403);
        abort_unless($attachment->storage_disk && $attachment->storage_path, 404);

        $disk = Storage::disk($attachment->storage_disk);
        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->download(
            $attachment->storage_path,
            $attachment->filename ?: 'attachment',
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }
}
