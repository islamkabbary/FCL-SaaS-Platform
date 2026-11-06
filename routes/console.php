<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bills every subscription month on its first day; a missed run catches up on the next one.
Schedule::command('billing:issue-monthly-charges')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
