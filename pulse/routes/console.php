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
Schedule::command('posts:promote-queued --per-run=1')->everyThirtyMinutes()->withoutOverlapping(10);

// Research-first synthesis (the new content model's writer): for queued drafts,
// Brave-search other outlets and write the whole article multi-source + "Our Take"
// BEFORE promotion, so it can clear the multi-source publish bar. Runs ahead of
// promote-queued. No-op until a web-search (Brave) key is configured.
Schedule::command('posts:synthesize --limit=5')->everyTenMinutes()->withoutOverlapping(12);

// Freshness updates: for recently PUBLISHED posts, fold in any later coverage.
// No-op until a web-search key is configured, so it is safe to schedule now.
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

// All clock-anchored jobs below run on New York time (config('app.display_timezone')),
// the site's official US editorial timezone — so "daily" means a US calendar day and
// lines up with the daily publish cap, which also counts the New York day. Timestamps
// stay stored in UTC; only the schedule's wall-clock is New York (and DST-stable).

// Daily cleanup (00:00 NY): delete drafts stuck longer than 24h — the un-fixable
// video / no-text pages that have exhausted their retries.
Schedule::command('posts:fix-drafts --reprocess-hours=0 --delete-hours=24')->dailyAt('00:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Web push: at most one notification per interval (default 2h), choosing the most
// important recent story. Runs often but self-gates on push_interval_hours.
Schedule::command('push:notify')->everyFifteenMinutes()->withoutOverlapping(10);

// Pull real Google ranking data once a day (no-op until Search Console is configured).
Schedule::command('seo:pull-rankings')->dailyAt('01:30')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Daily "top stories" digest to subscribers (08:00 US Eastern morning).
// Self-gates on digest_enabled + configured SMTP, so it's a no-op until set up.
Schedule::command('newsletter:digest')->dailyAt('08:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Fresh daily engagement content so there's a reason to return every day.
Schedule::command('polls:daily')->dailyAt('08:15')->timezone(config('app.display_timezone'))->withoutOverlapping();
Schedule::command('quiz:daily')->dailyAt('08:20')->timezone(config('app.display_timezone'))->withoutOverlapping();

// One opinion column a week, on a top story that hasn't had a column yet. Every
// news article already ends with its own labeled "Our Take", so standalone
// columns are kept rare and distinct (daily batches produced near-duplicates).
Schedule::command('opinions:generate --count=1')->weeklyOn(3, '09:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// One uplifting Story of Hope feature each morning, so the section is never empty.
// Grounded in the day's real news themes (resilience/community/generosity); counts
// toward the shared daily publish cap. Runs early so it's ready for the US morning.
Schedule::command('stories:generate --count=1')->dailyAt('06:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Weekly: learn hook/style patterns from top performers and update the AI guide.
Schedule::command('style:learn')->weeklyOn(1, '07:00')->timezone(config('app.display_timezone'))->withoutOverlapping();

// Daily "morning headlines" push (08:00 US Eastern morning) — a daily nudge
// to return. Breaking news still goes out immediately via push:notify.
Schedule::command('push:briefing')->dailyAt('08:00')->timezone(config('app.display_timezone'))->withoutOverlapping();
