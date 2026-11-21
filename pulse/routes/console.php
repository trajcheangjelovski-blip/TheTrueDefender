<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll news feeds every minute so stories are found fast (the poll is cheap; only
// NEW items cost anything). ingest:run only queues one IngestSourceJob per feed;
// the `ingest-worker` service processes feeds in parallel, so one slow rewrite no
// longer holds up the others. A breaking story goes straight into the fast lane
// (BreakingPublisher) inside its feed's job. withoutOverlapping(5): the lock
// auto-expires, so an interrupted run can never permanently block future runs.
Schedule::command('ingest:run')->everyMinute()->withoutOverlapping(5);

// Self-healing: fix any published post with a missing/broken image — INCLUDING
// opinion columns / manual posts (--all), which have no source_url and were
// previously skipped, so an image-gen hiccup on those now heals automatically.
Schedule::command('posts:backfill-images --all --limit=3')->everyTenMinutes()->withoutOverlapping(15);

// Web push: at most one notification per interval (default 2h), choosing the most
// important recent story. Runs often but self-gates on push_interval_hours.
Schedule::command('push:notify')->everyFifteenMinutes()->withoutOverlapping(10);

// Pull real Google ranking data once a day (no-op until Search Console is configured).
Schedule::command('seo:pull-rankings')->dailyAt('01:30')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Daily "top stories" digest to subscribers (08:00 US Eastern morning).
// Self-gates on digest_enabled + configured SMTP, so it's a no-op until set up.
Schedule::command('newsletter:digest')->dailyAt('08:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Fresh daily engagement content so there's a reason to return every day.
Schedule::command('polls:daily')->runInBackground()->dailyAt('08:15')->timezone(config('app.display_timezone'))->withoutOverlapping();
Schedule::command('quiz:daily')->runInBackground()->dailyAt('08:20')->timezone(config('app.display_timezone'))->withoutOverlapping();

// The configured daily opinion-generation schedule runs on a top
// story that hasn't had a column in 21 days. The near-duplicate problem that once
// made this weekly is handled by topic de-duplication, rotating column angles,
// recent-column phrasing to avoid, and PublishGate::originality() before publish.
Schedule::command('opinions:generate --count=1')->runInBackground()->dailyAt('11:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Weekly: learn hook/style patterns from top performers and update the AI guide.
Schedule::command('style:learn')->runInBackground()->weeklyOn(1, '07:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Daily "morning headlines" push (08:00 US Eastern morning) — a daily nudge
// to return. Breaking news still goes out immediately via push:notify.
Schedule::command('push:briefing')->dailyAt('08:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Event lifecycle: flag published previews of votes, elections and rulings once their results are due, for editorial review.
// No AI, no edits to the article; one flag per article.
Schedule::command('posts:flag-outdated-previews')->hourly()->withoutOverlapping();

Schedule::command('newsroom:dispatch')->everyMinute()->withoutOverlapping(2);
Schedule::command('posts:promote-queued --per-run=20')->everyMinute()->withoutOverlapping(10);
Schedule::command('newsroom:outbox')->everyMinute()->withoutOverlapping(10);
Schedule::command('posts:refresh-related')->everyFifteenMinutes()->withoutOverlapping(120)->runInBackground();
