<?php

namespace App\Http\Controllers;

use App\Jobs\StoreIncomingEmail;
use App\Models\CloudflareAccount;
use App\Services\Cloudflare\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives parsed inbound emails from the Cloudflare Email Worker.
 * Auth is a per-account HMAC signature over `timestamp + "." + rawBody`.
 */
class IncomingEmailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $accountId = $request->header('X-CF-Account');
        $signature = $request->header('X-CF-Signature');
        $timestamp = $request->header('X-CF-Timestamp');

        if (! $accountId || ! $signature || ! $timestamp) {
            Log::warning('cf webhook rejected: missing signature headers', ['account' => $accountId]);

            return response()->json(['error' => 'missing signature headers'], 400);
        }

        $account = CloudflareAccount::where('account_id', $accountId)->first();

        if (! $account || ! $account->webhook_secret) {
            Log::warning('cf webhook rejected: unknown account or no secret', ['account' => $accountId]);

            return response()->json(['error' => 'unknown account'], 401);
        }

        $valid = WebhookSignature::verify(
            secret: $account->webhook_secret,
            timestamp: (string) $timestamp,
            body: $request->getContent(),
            signature: (string) $signature,
            toleranceSeconds: (int) config('cloudflare.webhook.tolerance_secs', 300),
        );

        if (! $valid) {
            Log::warning('cf webhook rejected: invalid signature', [
                'account' => $accountId,
                'hint' => 'Worker WEBHOOK_SECRET may not match the stored webhook_secret — redeploy the worker.',
            ]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        StoreIncomingEmail::dispatch($account->id, $request->json()->all());

        return response()->json(['accepted' => true], 202);
    }
}
