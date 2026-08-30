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

// Paced editorial promotion: urgent stories publish immediately at ingest; the
// rest are queued and this promotes the highest-scoring ones (US politics first,
// then disasters) into the day's remaining slots — best-of-the-day, not first-come.
// Hourly cadence spreads the daily cap through the day; it waits when nothing on
// the queue clears the quality bar.
Schedule::command('posts:promote-queued --per-run=1')->hourly()->withoutOverlapping(10);

// Web research: search the open web for other outlets' coverage of recent stories
// and synthesize their new facts in (multi-source, less derivative). No-op until a
// web-search key is configured, so it is safe to schedule now.
Schedule::command('posts:research --limit=4')->everyFifteenMinutes()->withoutOverlapping(12);

// Self-healing: fix any published post with a missing/broken image — INCLUDING
// opinion columns / manual posts (--all), which have no source_url and were
// previously skipped, so an image-gen hiccup on those now heals automatically.
Schedule::command('posts:backfill-images --all --limit=3')->everyTenMinutes()->withoutOverlapping(15);

// Fast draft recovery (every 5 min): the inline ingest fix already publishes new
// stories at full length in real time, but if one slips to draft (transient AI/
// fetch hiccup) this retries it — re-fetch + rewrite and publish within ~5 min of
// the failure. Capped at 4 attempts/post so un-fixable pages (video/no-text) stop
// retrying. No deletion here. Only ever touches AI-ingested drafts.
Schedule::command('posts:fix-drafts --reprocess-hours=3')->everyFiveMinutes()->withoutOverlapping(10);

// Daily cleanup (04:00): delete drafts stuck longer than 24h — the un-fixable
// video / no-text pages that have exhausted their retries.
Schedule::command('posts:fix-drafts --reprocess-hours=0 --delete-hours=24')->dailyAt('04:00')->withoutOverlapping();

// Web push: at most one notification per interval (default 2h), choosing the most
// important recent story. Runs often but self-gates on push_interval_hours.
Schedule::command('push:notify')->everyFifteenMinutes()->withoutOverlapping(10);

// Pull real Google ranking data once a day (no-op until Search Console is configured).
Schedule::command('seo:pull-rankings')->dailyAt('05:30')->withoutOverlapping();

// Daily "top stories" digest to subscribers (12:00 UTC ≈ 7–8am US Eastern).
// Self-gates on digest_enabled + configured SMTP, so it's a no-op until set up.
Schedule::command('newsletter:digest')->dailyAt('12:00')->withoutOverlapping();

// Fresh daily engagement content so there's a reason to return every day.
Schedule::command('polls:daily')->dailyAt('12:15')->withoutOverlapping();
Schedule::command('quiz:daily')->dailyAt('12:20')->withoutOverlapping();

// Two high-quality opinion columns each day, based on the day's top stories.
Schedule::command('opinions:generate --count=2')->dailyAt('13:00')->withoutOverlapping();

// Weekly: learn hook/style patterns from top performers and update the AI guide.
Schedule::command('style:learn')->weeklyOn(1, '11:00')->withoutOverlapping();

// Daily "morning headlines" push (12:00 UTC ≈ 7–8am US Eastern) — a daily nudge
// to return. Breaking news still goes out immediately via push:notify.
Schedule::command('push:briefing')->dailyAt('12:00')->withoutOverlapping();
