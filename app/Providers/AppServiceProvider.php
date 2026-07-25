<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // When served over HTTPS (behind Coolify's proxy), force all generated
        // URLs — including Vite asset URLs — to https to avoid mixed-content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Web Push send results are otherwise SILENT: the channel's default
        // report handler dispatches these events but nothing listens, so a
        // rejected push (bad VAPID, 403 from Apple/FCM, expired endpoint) never
        // reaches the log. Surface both so "notifications don't arrive" is
        // diagnosable from storage/logs.
        Event::listen(NotificationFailed::class, function (NotificationFailed $e): void {
            $response = $e->report->getResponse();
            Log::warning('WebPush delivery failed', [
                'endpoint' => $e->report->getEndpoint(),
                'reason' => $e->report->getReason(),
                'status' => $response?->getStatusCode(),
                'body' => $e->report->getResponseContent(),
                'expired' => $e->report->isSubscriptionExpired(),
            ]);
        });

        Event::listen(NotificationSent::class, function (NotificationSent $e): void {
            Log::info('WebPush delivered', ['endpoint' => $e->report->getEndpoint()]);
        });
    }
}
