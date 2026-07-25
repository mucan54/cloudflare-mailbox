<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare API
    |--------------------------------------------------------------------------
    |
    | Base URL for the Cloudflare v4 REST API. Per-tenant credentials
    | (account id + API token) live in the `cloudflare_accounts` table; the
    | env values below are only fallbacks for the very first setup / tinkering.
    |
    */

    'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),

    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'api_token' => env('CLOUDFLARE_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Outbound sending
    |--------------------------------------------------------------------------
    |
    | 'api'  -> POST /accounts/{id}/email/sending/send (default, synchronous
    |           delivered/queued/bounced result)
    | 'smtp' -> Laravel `cloudflare` mailer (config/mail.php)
    |
    */

    'sending' => [
        'driver' => env('CLOUDFLARE_SEND_DRIVER', 'api'),
        'max_bytes' => 5 * 1024 * 1024, // 5 MiB hard limit (incl. attachments)
        // Account-relative path for the Email Sending suppression list. Kept
        // configurable in case Cloudflare versions the endpoint per account.
        'suppressions_path' => env('CLOUDFLARE_SUPPRESSIONS_PATH', 'email/sending/suppressions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding — API token template deep link
    |--------------------------------------------------------------------------
    |
    | Permission-group keys used to pre-fill the Cloudflare "Create Token" page
    | so onboarding is one click. The email* keys are pinned from
    | GET /user/tokens/permission_groups during setup (see docs/ARCHITECTURE.md
    | §8.2.1); adjust here if Cloudflare renames them.
    |
    */

    'token_template' => [
        'name' => 'Cloudflare Mailbox',
        'dashboard_url' => 'https://dash.cloudflare.com/profile/api-tokens',
        // Only these two pre-fill reliably via the template link (Cloudflare
        // exposes no stable short key for Email Routing). The two Email Routing
        // permissions must be added manually on the token page — the onboarding /
        // settings UI instructs the user to do so.
        'permission_groups' => [
            ['key' => 'email_sending', 'type' => 'edit'], // Account
            ['key' => 'zone', 'type' => 'read'],          // Zone
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook (Cloudflare Email Worker -> Laravel)
    |--------------------------------------------------------------------------
    |
    | Per-tenant `webhook_secret` (encrypted) is the source of truth; the env
    | value is a fallback. The signature is HMAC(secret, timestamp + "." + body)
    | and requests must arrive within `tolerance_secs`.
    |
    */

    'webhook' => [
        'secret' => env('CLOUDFLARE_WEBHOOK_SECRET'),
        'tolerance_secs' => (int) env('CLOUDFLARE_WEBHOOK_TOLERANCE', 300),
        // Attachments at or below this size travel inline (base64) in the
        // webhook body; larger ones are expected as an R2 key reference.
        'inline_attachment_max_bytes' => (int) env('CLOUDFLARE_INLINE_ATTACHMENT_MAX', 2 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments storage
    |--------------------------------------------------------------------------
    |
    | Any Laravel filesystem disk name. Defaults to the local disk so it works
    | out of the box with no extra setup. Set CLOUDFLARE_ATTACHMENTS_DISK=s3
    | (or any disk) for durable, off-container storage — `s3` works for AWS S3,
    | Cloudflare R2 and MinIO (only the endpoint/region differ). Downloads are
    | streamed through an authenticated, mailbox-scoped route, so any disk
    | works (a signed temporary URL is not required).
    |
    | Note: the local disk lives on the container's filesystem, which is
    | ephemeral on most PaaS deploys (files are lost on redeploy). Use s3/R2
    | if you need attachments to persist across deploys.
    |
    */

    'attachments_disk' => env('CLOUDFLARE_ATTACHMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Worker deploy
    |--------------------------------------------------------------------------
    |
    | The inbound Email Worker lives in cf/ and is deployed from Laravel via
    | `php artisan cf:deploy-worker`. These control naming and the wrangler
    | binary used by the deploy command.
    |
    */

    'worker' => [
        'name_prefix' => env('CLOUDFLARE_WORKER_PREFIX', 'mailbox-inbound'),
        'directory' => base_path('cf'),
        'wrangler_bin' => env('CLOUDFLARE_WRANGLER_BIN', 'npx wrangler'),
        'fallback_forward_to' => env('CLOUDFLARE_FALLBACK_FORWARD_TO'),
    ],

];
