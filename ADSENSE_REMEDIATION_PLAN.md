# AdSense Remediation — The New Content Model

**Date:** 2026-10-16
**Site:** https://thetruedefender.news (Laravel app in `pulse/`)
**Rejection:** 3rd — "Low value content" (scaled / single-source AI rewrites)

This is the plan agreed after the 3rd rejection: **stop polishing thin content and change
the model.** Fewer, deeper, genuinely-sourced articles; a real named editor; and a hard cut
of the low-value back-catalog. No OpenAI credits were spent producing this, and none of the
commands below re-process old posts with AI.

> **Root cause (why 3 rejections):** every article was a single-source AI rewrite of one
> outlet's story, published at scale (~2,267), under an anonymous "Editorial Team" byline —
> the textbook case for AdSense *Low value content*, Google *thin content with little added
> value*, and *scaled content abuse*. Surface fixes (placeholders, AI tells, UX) didn't change
> that model, so the verdict didn't change.

---

## What changed in the code (this commit)

### 1. Named-author E-E-A-T system
- `database/migrations/2026_10_16_120000_add_author_profile_to_users.php` — author profile
  columns on `users` (slug, title, bio, credentials, photo, X/LinkedIn, public email, `is_author`).
- `app/Models/User.php` — `authors()` scope, `isPublicAuthor()`, `authorUrl()`, `photoUrl()`.
- `app/Models/Post.php` — byline now uses the **real assigned author** when one is set
  (`public_author`, `public_author_url`, `public_author_is_person`, `public_author_title`,
  author initials), falling back to the brand only for legacy posts.
- `app/Http/Controllers/AuthorController.php` + route `/author/{slug}` + `resources/views/authors/show.blade.php`
  — a real author page (bio, credentials, socials, their articles) with `ProfilePage`/`Person` schema.
- `resources/views/posts/show.blade.php` — byline links to the author page; `NewsArticle`
  JSON-LD emits a **Person** author (with `jobTitle`, `url`) + a `publisher.logo`.
- `app/Filament/Resources/UserResource.php` — admin UI to manage author profiles/photos.
- `app/Console/Commands/AuthorsEnsure.php` — `authors:ensure` creates/updates the named author
  (default **Trajche Angjelovski, Editor-in-Chief**) and can assign the byline. **No AI.**

### 2a. The new writer — research-first synthesis (Brave → synthesize → "Our Take")
- `app/Services/Rewriter.php::synthesize()` — writes the **whole article once** from the
  primary report **plus** other outlets found on the open web. Rules: weave all sources into
  original prose, **never name the outlets in the body** (attribute quotes to the actual
  speaker), prefer corroborated facts, add real background/context, and end with a clearly
  labeled **"Our Take"** opinion section (from your `editorial_stance`).
- `app/Console/Commands/PostsSynthesize.php` — `posts:synthesize`: for each queued draft,
  Brave-searches other outlets, then calls `synthesize()`. **Cost-safe** — only spends an AI
  call when enough extra sources are found to clear the multi-source bar. Scheduled every 10 min,
  ahead of promotion. No-op without a Brave key.
- `app/Console/Commands/PostsResearch.php` — refocused to **freshness updates for published
  posts** only (drafts are now written multi-source up front by `posts:synthesize`).
- `app/Services/IngestService.php` — always scores auto-publish items (0–100) so the queue can
  rank them (the score decides immediate-vs-hold).
- `app/Filament/Pages/ManageAiSettings.php` — new **Publishing & Daily Volume** controls:
  `daily_publish_cap` (10), `min_sources_to_publish` (3), `max_sources_per_post` (4),
  `publish_now_score` (85), `promote_score_floor` (70).

> Articles use the **featured image only — no in-body photos** (decision: avoids the copyright
> exposure of republishing others' photos). Visual breakup comes from the Key Takeaways box,
> subheadings and the "Our Take" section.

### 2b. The new publish bar (multi-source, enforced)
- `app/Support/PublishGate.php` — one place that defines "may this go live": **≥3 distinct
  sources**, **≥600 words**, no single-source "the report says" tells, no implied outreach, no
  placeholders. Thresholds are Settings (`min_sources_to_publish`, `min_publish_words`).
- `app/Console/Commands/PostsPromoteQueued.php` — auto-promotion now blocks anything that fails
  the gate (instead of only checking word count).
- `app/Services/IngestService.php` — a freshly ingested, single-source item is **never
  auto-published immediately** when the multi-source bar is on; it's queued for enrichment.
- `app/Console/Commands/PostsResearch.php` — now enriches **queued drafts** (not just published
  posts) so they can reach the multi-source bar *before* promotion. (Already existed; this
  closes the ordering gap the new gate created.)
- `app/Http/Controllers/PostController.php` — retired/archived posts now return **HTTP 410 Gone**
  (permanent) instead of 404, so Google deindexes them faster.

### 3. The volume cut / clean slate
- `app/Console/Commands/PostsWipe.php` — `posts:wipe`: **clean slate** — permanently delete ALL
  posts (backup written first; `--apply` + confirmation required). Cascades to comments/social/tags.
- `app/Console/Commands/PostsPruneThin.php` — `posts:prune-thin` retires only the low-value tail
  instead. **Reversible by default** (archive → 410, with a JSON backup first). `--undo`, `--purge` opt-in.

### 3b. Site-quality fixes (what a human reviewer sees)
- `app/Console/Commands/OpinionsGenerate.php` + `routes/console.php` — opinion columns cut from
  **2/day to 1/week**, bylined to the named author, and **one column per story** (topic overlap
  is checked before the AI call). Daily batches had produced 3 near-identical columns per story.
- `resources/views/categories/opinion.blade.php` — presented as labeled **editor's columns**
  ("By Trajche Angjelovski · Editor-in-Chief"), not an empty forum; "0 replies" counters hidden.
- `app/Services/Rewriter.php` + `app/Services/IngestService.php` — **Story of Hope** limited to
  genuinely uplifting stories; deaths/tragedies/crime are re-filed to US News by a safety net.
- `app/Services/Rewriter.php` — headlines must be **accurate and descriptive** (no "slams/rips",
  no teaser questions); the click-through style learner can no longer override that.
- `resources/views/posts/show.blade.php` — removed the auto **FAQ block + FAQPage schema**, the
  repeated pull-quote / "Copy for Truth Social" box, the mid-article newsletter ask and the
  social-follow promo. One newsletter ask remains, at the end.
- `app/Http/Controllers/AdsTxtController.php` + route — **`/ads.txt` is generated** from the
  publisher ID in Admin → AI Settings → Google AdSense (static `public/ads.txt` removed).

### 4. Truthful About / Editorial Standards / Newsroom
- `resources/views/pages/{about,editorial-standards,newsroom}.blade.php` — rewritten to describe
  the **real** process (named accountable editor; multiple sources per story; AI-assisted but
  human-edited; fewer, substantial pieces). **Removed** the old "we rewrite each story in our own
  words" line that read like an admission of spinning.

---

## Deploy & run sequence (on the server, `/opt/ttd`)

Per `DEPLOY.md`: never restart `app` mid-ingest.

```bash
# 0. Pull the code
git pull

# 1. Migrate (adds author-profile columns)
php artisan migrate

# 2. Create the named editor (Trajche Angjelovski). Idempotent, no AI.
#    Link it to your admin login by passing your admin email; add socials if you have them.
php artisan authors:ensure --email=YOUR_ADMIN_EMAIL --x= --linkedin=
#    Then open /admin → Admins → your user → add a real photo + tighten the bio.

# 3. CONFIGURE THE NEW MODEL in admin → AI Settings (or tinker):
#    - Open-Web Research: paste your BRAVE SEARCH KEY, provider = brave, enabled = on.
#      (REQUIRED — without it posts:synthesize can't gather sources and nothing publishes.)
#    - Editorial perspective ("Our Take"): your house viewpoint (or leave blank for neutral).
#    - Publishing & Daily Volume: daily_publish_cap=10, min_sources_to_publish=3,
#      max_sources_per_post=4, publish_now_score=85, promote_score_floor=70.
#    - Teach the AI (house style) as desired.

# 4. CLEAN SLATE — delete all old posts (backup written first; confirmation required).
php artisan posts:wipe                                         # dry run: shows the count + writes backup
php artisan posts:wipe --apply                                 # delete everything, start fresh
#    (Prefer keeping a curated few instead? use: posts:prune-thin --all --keep-top=50 --apply)

# 5. Assign the byline to the curated survivors (pick a sane number; see byline note).
php artisan authors:ensure --assign-recent=60

# 6. Refresh and clear caches so removed URLs drop and views pick up the byline.
php artisan view:clear && php artisan route:clear && php artisan cache:clear
docker compose restart app     # per DEPLOY.md
```

Then in Search Console: the archived URLs now return 410 and leave the index over the next
few crawls; the sitemaps already exclude them automatically.

---

## Decisions you still own

**Byline strategy.** One human credited with thousands of political stories is itself a weak
E-E-A-T signal. Recommended: keep Trajche as the accountable **Editor-in-Chief** (the masthead),
and byline the **curated survivors + all new synthesized pieces** to him — i.e. after the volume
cut, `--assign-recent=N` on what you keep, not `--assign-all` on the whole legacy catalog. Add a
second real author later if you can; two named people beats one person "writing" everything.

**Archive vs. purge.** Default is reversible archive (→ 410). Only `--purge` after you've
confirmed the backup if you truly want the rows gone (purge can't be undone from the status backup).

---

## ⚠️ Hard dependency: web search must be on

The new model holds every story until it has ≥3 sources, which `posts:synthesize` gathers from the
open web via **Brave**. **If no Brave key is configured, `posts:synthesize` is a no-op, drafts never
reach the bar, and the daily cleanup deletes them after 24h — so nothing new publishes.** Either
configure the Brave key, or temporarily set `min_sources_to_publish=1` (which re-enables
single-source publishing and is *not* recommended for AdSense). Brave Search has a free tier that
easily covers ~10 stories/day.

---

## Honest reality + fallbacks

- **This domain has a rejection history.** Even a genuinely improved site can take weeks and
  several crawl cycles to be reconsidered. Build and run the new model for a few weeks, accumulate
  a real body of multi-source articles, *then* request review — don't resubmit the day you deploy.
- **Volume matters.** 50–100 strong, multi-source, named-author articles is a far better
  application than 2,267 thin rewrites. Cut hard.
- **Don't rely only on AdSense.** Weigh alternatives (Ezoic, Media.net — note Mediavine/Raptive
  have traffic thresholds) and lean on the assets you already own: the **newsletter** and the
  **Patriot Shop** / direct sponsorship. These don't depend on Google's approval at all.

---

## Pre-review checklist

- [ ] `php artisan migrate` run; author columns present.
- [ ] `authors:ensure` run; `/author/trajche-angjelovski` loads with a real photo + bio.
- [ ] An article shows the named byline linking to the author page; view source shows `Person` JSON-LD.
- [ ] Volume cut applied; a retired URL returns **410**; sitemaps no longer list it.
- [ ] About / Editorial Standards / Newsroom read truthfully (no "rewrite each story" line).
- [ ] Brave key configured; `posts:synthesize --dry` finds extra sources; new posts publish with
      **3+ listed sources**, 600+ words, and an **"Our Take"** section — and no outlet names in the body.
- [ ] `/ads.txt` shows `google.com, pub-…, DIRECT, f08c47fec0942fa0` (publisher ID saved in admin).
- [ ] AdSense → **Privacy & messaging → GDPR message** turned on (Google requires a certified
      consent tool to serve ads to EEA/UK/Swiss visitors).
- [ ] An article shows no FAQ block, no pull-quote box, and a single newsletter ask at the end.
- [ ] Opinion page shows bylined columns, no duplicate topics, no "0 replies" counters.
- [ ] Story of Hope contains only genuinely uplifting stories.
- [ ] Let the new model run and accumulate **~30–50 strong articles over 3–4 weeks before**
      requesting review (an almost-empty site is also rejected as "low value").
