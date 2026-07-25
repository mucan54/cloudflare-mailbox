<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address-book suggestions for the compose "To/Cc/Bcc" fields — distinct
 * people the mailbox has corresponded with (received senders, sent
 * recipients) plus saved contacts.
 */
class RecipientController extends Controller
{
    public function suggest(Request $request): JsonResponse
    {
        $mailbox = $request->user();
        $q = trim($request->string('q')->toString());
        $like = '%'.$q.'%';
        $out = collect();

        // Saved contacts (highest priority)
        $mailbox->contacts()->whereNotNull('email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->orderByDesc('favorite')->limit(20)->get(['name', 'email'])
            ->each(fn ($c) => $out->push(['name' => $c->name ?: $c->email, 'email' => $c->email]));

        // People who wrote to this mailbox
        $mailbox->emails()->whereNotNull('from_email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('from_email', 'like', $like)->orWhere('from_name', 'like', $like)))
            ->latest('received_at')->limit(80)->get(['from_email', 'from_name'])
            ->each(fn ($e) => $out->push(['name' => $e->from_name ?: $e->from_email, 'email' => $e->from_email]));

        // People this mailbox has sent to (recipients are a JSON array)
        $mailbox->sentEmails()->latest('sent_at')->limit(80)->get(['to'])
            ->each(function ($s) use ($out) {
                foreach ((array) $s->to as $addr) {
                    if (is_string($addr) && $addr !== '') {
                        $out->push(['name' => $addr, 'email' => $addr]);
                    }
                }
            });

        $results = $out
            ->filter(fn ($r) => filled($r['email'])
                && ($q === '' || str_contains(mb_strtolower($r['name'].' '.$r['email']), mb_strtolower($q))))
            ->unique(fn ($r) => mb_strtolower($r['email']))
            ->take(8)
            ->values();

        return response()->json(['data' => $results]);
    }
}
