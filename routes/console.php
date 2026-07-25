<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Push a reminder ~30 minutes before upcoming events. Requires the Laravel
// scheduler to be running (a cron entry: * * * * * php artisan schedule:run).
Schedule::command('calendar:remind')->everyFiveMinutes()->withoutOverlapping();
