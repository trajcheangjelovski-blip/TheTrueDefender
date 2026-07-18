<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll news feeds every 5 minutes so new stories reach the site quickly.
Schedule::command('ingest:run')->everyFiveMinutes()->withoutOverlapping();

// Pull real Google ranking data once a day (no-op until Search Console is configured).
Schedule::command('seo:pull-rankings')->dailyAt('05:30')->withoutOverlapping();
