<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        // Scoped to THIS mailbox — never touches another mailbox's row for the
        // same device, so one device stays registered under every account.
        // (The package's updatePushSubscription would reassign a shared endpoint
        // to a single owner, starving the other accounts of notifications.)
        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?: 'aes128gcm',
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string']])['endpoint'];

        // Only remove this mailbox's registration for the device, not others'.
        $request->user()->pushSubscriptions()->where('endpoint', $endpoint)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Send a test push to this mailbox's registered devices and report the
     * per-device outcome. Expired/gone subscriptions are pruned so real
     * notifications stop wasting effort on dead endpoints. This is the
     * per-device health check for the push pipeline.
     */
    public function test(Request $request): JsonResponse
    {
        $mailbox = $request->user();
        $subs = $mailbox->pushSubscriptions()->get();

        if ($subs->isEmpty()) {
            return response()->json(['sent' => 0, 'failed' => 0, 'pruned' => 0, 'reason' => 'no_subscriptions']);
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        if (! $publicKey || ! $privateKey) {
            return response()->json(['sent' => 0, 'failed' => 0, 'pruned' => 0, 'reason' => 'vapid_not_configured'], 422);
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ]]);

        $payload = json_encode([
            'title' => 'Test bildirimi ✅',
            'body' => 'Bildirimler bu cihazda çalışıyor.',
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'tag' => 'push-test',
            'data' => ['url' => '/f/inbox'],
        ]);

        foreach ($subs as $sub) {
            $webPush->queueNotification(
                // Fall back to aes128gcm (iOS-compatible) when unset — the
                // library's own Subscription default is the legacy aesgcm.
                new Subscription($sub->endpoint, $sub->public_key, $sub->auth_token, $sub->content_encoding ?? ContentEncoding::aes128gcm),
                $payload,
            );
        }

        $sent = 0;
        $failed = 0;
        $pruned = 0;
        $errors = [];

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;

                    continue;
                }

                $failed++;
                $status = $report->getResponse()?->getStatusCode();
                $errors[] = trim(($status ? $status.' ' : '').$report->getReason());

                // Drop endpoints the push service has retired so future sends skip them.
                if ($report->isSubscriptionExpired()) {
                    $mailbox->pushSubscriptions()->where('endpoint', $report->getEndpoint())->delete();
                    $pruned++;
                }
            }
        } catch (\Throwable $e) {
            // VAPID signing / encryption blows up here (e.g. a malformed key)
            // — return the reason instead of a 500 so the user can see it.
            return response()->json([
                'sent' => 0,
                'failed' => $subs->count(),
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'sent' => $sent,
            'failed' => $failed,
            'pruned' => $pruned,
            'devices' => $subs->count(),
            'errors' => array_values(array_slice(array_unique($errors), 0, 3)),
        ]);
    }
}
