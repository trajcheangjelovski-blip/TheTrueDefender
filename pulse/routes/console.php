<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll news feeds every 5 minutes so new stories reach the site quickly.
// withoutOverlapping(10): the lock auto-expires after 10 min, so an interrupted
// run (e.g. a container restart mid-ingest) can never permanently block future runs.
Schedule::command('ingest:run')->everyFiveMinutes()->withoutOverlapping(10);

// Self-healing: fix any published post that ended up with a missing/broken image.
Schedule::command('posts:backfill-images --limit=3')->everyTenMinutes()->withoutOverlapping(15);

// Pull real Google ranking data once a day (no-op until Search Console is configured).
Schedule::command('seo:pull-rankings')->dailyAt('05:30')->withoutOverlapping();
