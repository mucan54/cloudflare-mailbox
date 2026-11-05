<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public profile photo of a mailbox. Public on purpose: it is shown in the
 * portal (plain <img> tags carry no bearer token) and, when the user opts in,
 * in the signature of outgoing mail. Addressed by an unguessable per-mailbox
 * token, so photos can't be enumerated by mailbox id or email address.
 */
class AvatarController extends Controller
{
    /** A 1×1 transparent GIF. */
    private const BLANK_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __invoke(string $token): Response
    {
        $mailbox = Mailbox::where('avatar_token', $token)->firstOrFail();
        $disk = Storage::disk(Mailbox::avatarDisk());

        if (! $mailbox->avatar_path || ! $disk->exists($mailbox->avatar_path)) {
            // The photo was removed, but signatures in already-sent mail still
            // point here: answer with an empty image rather than a 404, so
            // recipients see the photo disappear instead of a broken icon.
            return response(base64_decode(self::BLANK_GIF), 200, [
                'Content-Type' => 'image/gif',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        return $disk->response($mailbox->avatar_path, null, [
            // URLs carry a ?v= per photo, but the base URL (used in sent mail)
            // stays the same when the photo changes — keep the cache short.
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
