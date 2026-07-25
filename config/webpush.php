<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        // The VAPID subject MUST be a mailto: or https:// URL — push services
        // (Apple/iOS especially) reject a bare value like "mailbox". Normalise
        // it so a misconfigured VAPID_SUBJECT can never silently break push:
        // an email becomes mailto:<email>, anything else falls back to APP_URL.
        'subject' => (static function () {
            $subject = (string) (env('VAPID_SUBJECT') ?: env('APP_URL', 'https://localhost'));
            if (preg_match('#^(mailto:|https?://)#i', $subject)) {
                return $subject;
            }

            return str_contains($subject, '@')
                ? 'mailto:'.$subject
                : (env('APP_URL') ?: 'https://localhost');
        })(),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The Guzzle client options used by Minishlink\WebPush.
     */
    'client_options' => [],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
