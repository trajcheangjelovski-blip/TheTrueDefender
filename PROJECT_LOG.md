# TheTrueDefender — Project Log

A running record of everything built on this project. Newest entries at the top.
The live application lives in the **`pulse/`** folder (Laravel). The original static
design mockup files (`index.html`, `css/`, `js/`, `about.html`, …) remain at the
project root as reference only — they are **not** the running site.

---

## Current status

**LIVE in production at https://thetruedefender.news** (Hetzner CX33, `204.168.153.231`, Dockerized, auto-HTTPS via Caddy). A custom, self-hosted news portal + shop:
- Stack: **Laravel 13 + Filament v3 admin**, PostgreSQL + Redis, Dockerized (SQLite in local dev).
- Public site (dark 3D design) + admin; **Stripe embedded card checkout**; subscribers + web push; AI news ingest (OpenAI, deep-rewrite + thin-content gate + AI-tell sanitizer); daily poll/quiz/opinions; hook CTR learning; Google Discover sitemaps; affiliate program.
- Content: 357 published articles, all ≥400 words (median 505). Deploy: `git pull` on `/opt/ttd` + `docker compose restart app` (restart only for PHP/Blade; static CSS/JS are cache-busted). **Never restart `app` while a background `docker compose exec` job (expand/ingest) runs.**

**Pending config the user still owns (not code):**
- **Email provider** — connect Resend/SMTP in admin Email settings to activate the daily digest + reply notifications (currently `MAIL_MAILER=log`, so no email sends). Biggest untapped daily-retention lever.
- **Social auto-posting** — add Truth Social / Telegram channel tokens in Automation → Social Channels (system is built; posts fire on publish once a channel exists). Biggest viral lever.
- **Google** — submit `/sitemap.xml` + `/news-sitemap.xml` in Search Console; register in Google Publisher Center for News/Discover. Re-request **AdSense** review after content matures ~2 weeks (consider Ezoic/Media.net in parallel — AdSense is strict on AI-assisted news).
- Admin login: `trajce_angelovski@hotmail.com`.

**Local dev quick start** (from `pulse/`, using the portable PHP at `C:\Users\Angjelovski-PC\php\php.exe`):
- Serve: `php artisan serve --port=8010` → site at http://127.0.0.1:8010, admin at `/admin`
- Background jobs: `php artisan queue:work`  •  automation: `php artisan schedule:work`
- Admin login: `trajche.angjelovski@mcash.mk` (password set during setup — change it)

---

## 2026-08-02 — "Up Next" card (beat the one-article social bounce)
- Data showed the problem: ~2,264 on-site headline impressions but only **7 clicks** and 2 subscribers — real Truth Social traffic that reads one article and leaves. Fix: an end-of-article **Up Next** card surfacing the single best next read.
- `PostController` picks it: **shares a topic → same category → most-read overall** (never the current post; excluded from the related grid to avoid dupes). Card shows image/category/headline/reading-time + "Continue reading". It's a normal `/post/{slug}` link, so it's **auto-tracked as a headline click** (feeds the hook-CTR learning) and keeps clean indexable URLs + analytics.
- Chose **Option A (click card)** over infinite-scroll auto-load deliberately: no AdSense-policy risk (mid re-review), no Core-Web-Vitals regression, no fragile URL/back-button/ad-reinit handling. Verified live — e.g. a Ukraine oil-refinery story's Up Next resolved to "Kyiv missile attack kills at least nine" (topic match). Themed card, stacks on mobile, no console errors. No migration.

## 2026-08-02 — Smart notification opt-in bar (conversion-optimized)
- User wanted more app-installs / push opt-ins. Instead of a cold "install/allow" popup (which gets Block'd → permanently burns the visitor), added an **engagement-triggered opt-in bar** (`#pushBar` in `partials/consent`, logic in `audience.js` `initPushBar`, themed bottom bar in `style.css`).
- **Fires only after engagement:** 50% scroll depth, 30s dwell, or a **return visit** (`dp_visits` counter → prompt at 6s for repeat visitors). **Two-step:** soft "Yes, notify me" → then the real browser permission. **Platform-correct:** iPhone (non-standalone) gets the Add-to-Home-Screen steps (only way iOS push exists), Android/desktop get the prompt. Never stacks with the cookie banner or email popup; **7-day snooze** on "Not now"; skips users already subscribed (`dp_push`) or hard-blocked. Reuses the existing `enablePush()`/SW plumbing.
- Copy is pure value-led ("Never miss a breaking story"); a commented alt line ties it to the shop ("…and when we drop free gifts") — one-line swap. Advised AGAINST a gift-for-opt-in incentive (junk subscribers, deliverability, credibility) and against an AI-chat installer (poor ROI vs a static platform-aware panel). Deployed + verified live.

## 2026-08-02 — Takeaways/FAQ link into content (fix dead-end feedback)
- User flagged the FAQ accordion as a passive dead-end ("no link / not doing anything"). Fixed: `App\Support\TopicLinker` wraps the **first mention of each of the post's topics** in the takeaways bullets + FAQ answers with a link to that **topic hub** (safe: HTML-escaped input, whole-word, once per topic, real hubs only). Plus a **"Full coverage: <topic> →" CTA** under the FAQ pointing at the post's most substantial hub (`>= MIN_STORIES`, skips thin ones).
- `FAQPage` JSON-LD stays plain text (links live only in the visible box, so schema stays clean). Themed `.content-link` + `.faq-more`. Deployed + verified live (e.g. "Engels Airfield" links to its hub; "Full coverage: Russia-Ukraine War"). No migration.
- Note: inline links point at any real hub (routes the reader even to thin/growing hubs); the prominent CTA is gated to substantial hubs only.

## 2026-08-02 — Key Takeaways box + FAQ block (FAQPage schema)
- **DB:** `posts.takeaways` + `posts.faqs` (JSON). AI now emits both on ingest in the **same rewrite call** (no extra cost/latency) alongside tags — schema extended with `takeaways` (string[]) + `faqs` ([{question,answer}]); `Rewriter::cleanTakeaways/cleanFaqs` sanitize (strip tags/dashes, cap 4, run through `ArticleSanitizer`).
- **Article page:** "⚡ The bottom line" summary box under the lead (dwell + featured-snippet bait) + a themed **FAQ accordion** after the body; **`FAQPage` JSON-LD** in the head when FAQs exist (SERP rich-result eligibility + CTR). Both styled to the dark theme.
- **Admin:** post editor gains a collapsible **Key Takeaways & FAQ** section (simple repeater for bullets + Q/A repeater), editable like meta/tags.
- **Backfill:** `ContentEnricher` service + **`content:enrich`** command (`--all`, `--limit=N`; one AI call per post, safe no-op without a key).
- **DEPLOYED to production + backfilled all 397 posts** (verified live: real factual bullets + genuine reader FAQs, e.g. "Why is the Engels airfield important?", FAQPage schema present, HTTP 200). Rationale/benefits: dwell-time + featured snippets (Takeaways) and rich-result CTR + long-tail question capture (FAQ), plus an "added value" signal against the AdSense low-value flag. FAQ rich results are Google's discretion — treated as upside; on-page dwell + CTR-when-shown are the reliable wins.

## 2026-08-02 — Topic/tag system: evergreen hub pages (durable long-tail SEO)
- **New `tags` + `post_tag`** (many-to-many). `Tag` model (slug route key, `publishedPosts`, `idsForNames()` normalizer → Title-Case, de-dupes by slug, ≤40 chars). `Post::tags()` + `syncTagsFromNames()` (caps 6).
- **AI auto-tagging on ingest (free):** Rewriter's JSON schema/prompt now also returns `tags` (3–5 evergreen subjects — people/places/orgs/issues); `IngestService` attaches them after post-create. No extra API call — rides the existing rewrite.
- **Public:** `/topics` directory + `/topic/{slug}` hub pages (reuse card grid; "Related topics" via tag co-occurrence). Topic chips on each article (internal linking + dwell). Nav gains **Topics**. Hub pages added to `sitemap.xml`; article `NewsArticle` JSON-LD gains `keywords`; hub pages emit `CollectionPage` JSON-LD.
- **Admin:** Content → **Topics** (`TagResource`: story counts, active toggle, "View hub", edit/merge) + a **Topics** multi-select on the post editor (create-inline).
- **Backfill for the 357 existing posts:** `TagGenerator` service + **`tags:backfill`** command (`--all`, `--limit=N`; one small AI call per post, safe no-op without a key). Run on the server after deploy.
- Verified locally (SQLite): migration ran; seeded 4 posts → hub, directory, related-chips, article chips, nav link, sitemap URLs, and JSON-LD `keywords` all render (HTTP 200, no console errors). Fixed a SQLite `HAVING`-without-`GROUP BY` error in the topics index (switched to `whereHas`). Test tags removed.
- **Thin-hub guard (`Tag::MIN_STORIES` = 3):** the backfill created ~1,097 topics from 397 posts — a long tail of 1–2 story hubs (thin-content/AdSense risk). Hubs below 3 published stories are kept out of the `/topics` directory + sitemap and carry `noindex, follow`; they still render (article chips never 404) and auto-become indexable once they reach 3. Result on prod: **113 substantial hubs** listed/indexed, ~984 thin ones noindexed.
- **DEPLOYED to production (thetruedefender.news):** migrated, restarted, and **`tags:backfill` run over all 397 posts** (top hubs: donald-trump 18, iran 17, us 15, russia-ukraine 15). Verified live — directory/hubs/chips/sitemap (113 topic URLs)/noindex all correct.
- **Content note flagged to user:** the two biggest topics were "Labour Party" (37) + "Andy Burnham" (33) — UK politics — confirming the standing note to drop BBC/UK-heavy aggregator feeds so topics skew American.
- **User actions:** two config levers walked through this session — connect **Resend/SMTP** (activates the dormant daily digest) and register in **Google Publisher Center** + submit sitemaps (opens News/Discover).

## 2026-08-02 — Growth: Google Discover sitemaps, daily/breaking push, share pull-quote
- `/sitemap.xml` (all posts/categories/pages, 30-min cache) + `/news-sitemap.xml` (Google News format, last 48h); `robots.txt` points to both. `max-image-preview:large` meta + hero (1600×900) OG image → Google Discover eligibility (NewsArticle JSON-LD already present).
- `push:notify` now sends **breaking** news immediately (bypasses the interval); new `push:briefing` sends a daily "☀️ morning headlines" push (scheduled 12:00 UTC).
- Click-to-share **pull quote** in each article (X / Truth / Telegram / copy).
- User action to activate Discover: submit sitemaps in Search Console + register in Google Publisher Center.

## 2026-08-02 — Hook learning: CTR tracking + weekly style-learning
- Headline **impressions** (IntersectionObserver) + **clicks** tracked per post (`posts.impressions/clicks`, `/track/*` endpoints) → real hook CTR.
- `style:learn` (weekly) distills a "house-hook guide" from top performers (CTR once ≥8 posts have ≥30 impressions & total ≥40 clicks, else views) and injects it into the rewrite prompt as guidance. Admin: Shown/Clicks/CTR columns on Posts + read-only guide panel in AI settings.

## 2026-08-02 — AdSense "low value content" remediation
- Denied for thin/scaled content. Fixes: deeper rewrites (550–850w, structured, original framing), **thin-content publish gate** (`min_publish_words`, default 400 → sub-floor pieces held as draft), AI-tell **`ArticleSanitizer`** (SEO/meta + "summary provided"/"as an AI"/"details were not provided" phrases) applied on generate + `posts:sanitize`, prompt hardened to never reveal inputs or comment on missing info.
- **Content reworking** (`posts:expand`): re-fetched + re-wrote thin published articles, unpublished the unfixable. Result: **443 published (76% thin) → 357 published, 100% ≥400 words** (median 505), 89 held as drafts, 0 thin remaining.
- Guidance to user: AI-aggregated news is exactly what "scaled content" targets — let it mature ~2 weeks before re-review; pursue Ezoic/Media.net in parallel; drop partisan aggregator feeds.

## 2026-08-02 — Daily engagement content
- **Daily rotating poll** (`polls:daily`) + **daily news quiz** (`quiz:daily`, `/quiz` page w/ client-side scoring + homepage teaser), both generated from the day's headlines and scheduled.
- **Daily opinion columns** (`opinions:generate`) upgraded to substantial 550–750w columns from the day's TOP stories (was ≤180w discussion-starters), sanitized + 400w floor, scheduled twice daily.
- Homepage **Most Read This Week** + **Most Discussed** (with a top-comment teaser + comment counts on cards & article meta).

## 2026-08-02 — Retention plumbing (email / push / PWA)
- **Daily email digest** (`newsletter:digest` + `DigestMail` + signed unsubscribe) — built, **dormant until an SMTP/Resend provider is set** in admin Email settings (runtime mail-config override; `MAIL_MAILER=log` by default so nothing sends yet).
- **Comment reply notifications** (`CommentObserver` + `ReplyNotification`) — emails the parent commenter on an approved reply (needs the mail provider too).
- **PWA**: web app manifest + 192/512 icons; SW gained install/activate/fetch handlers (installable). **iOS-only** "Add to Home Screen" banner — Android WebAPK triggers Play Protect "unsafe app blocked", so the install nudge is suppressed on Android.
- **Conversion**: end-of-article signup box, desktop **exit-intent** popup (dismissal expires after 7 days), shop trust signals.

## 2026-08-02 — Credibility pass (with partial revert per user)
- Removed **placeholder legal text** (Terms/Privacy), "Join thousands…" → honest copy, removed public view counts.
- New **Editorial Standards**, **Corrections**, **Our Newsroom** pages + real emails (`news@`/`corrections@`/`support@`) on About/Contact + footer links; brand byline links to standards.
- Shop briefly relabeled "Patriot Shop" with all-in "delivered" pricing — **reverted to "Free Gifts" + FREE-plus-shipping + 8 homepage products** at the user's request. A "Sources" block was added to articles then **removed** (posts are AI-reworked). Newsletter popup de-stacked (waits for the cookie choice).

## 2026-08-02 — Storage & image pipeline
- **Media & Storage** admin page (files marked used/unused by DB refs incl. hero/card/thumb variants, sizes, delete orphans). Deleted 137 orphaned files (~80 MB).
- **Image compression**: AI-generated + downloaded source images now stored as lean JPEGs; `images:compress` re-encoded existing bases → **415 MB → 78 MB**; post variants also capped ≤300 KB. Admin uploads auto-compressed ≤300 KB with an optional **watermark** toggle; **product uploads preserve PNG transparency** (a prior bulk compress flattened some product PNGs — user re-uploads those; command now skips products).

## 2026-08-02 — Performance (PageSpeed mobile 32 → 71)
- LCP fix: first hero slide eager + `fetchpriority=high` + responsive `<link rel=preload>`; all post images emit `srcset/sizes`. Caddy **cache headers** (immutable CSS/JS, 30-day media). Google Fonts non-render-blocking. Composited "pulse" animation; particle canvas skipped <768px. GA/AdSense deferred to idle/interaction (GA later moved back to **immediate** for reliable tracking). Result: CLS 0.021, TBT 10ms; remaining LCP is Slow-4G-lab bound.

## 2026-08-02 — Shop UX: variations, zoom, cart drawer, image fit
- **Product variations** (color/size/style; per-variant price/sale/stock/image) + **short description** + **product details**; variant-aware cart/checkout/orders (`product_variants`, composite cart key, `order_items.variant_id/variant_label`). Storefront swatch selector with live price + image swap.
- **Mini-cart drawer** slides in on add-to-cart (contents + Proceed to Checkout).
- Product image **click-to-zoom lightbox**; product cards/pages use `object-fit:contain` (no crop).

## 2026-08-02 — Content quality & platform fixes
- **Duplicate posts**: root cause was deduping on BBC's rotating tracking `guid`; switched to a **stable normalized-URL** key (global across feeds). Removed one real on-web duplicate + cleared stale duplicate skip-logs.
- **Freshness gate**: only import stories published within N hours (`ingest_max_age_hours`, default 3; date-less items pass through).
- **Brand byline**: personal author hidden site-wide → "TheTrueDefender" (`Post::public_author`); JSON-LD author = Organization.
- **Timezones**: admin date pickers use `admin_timezone` (Europe/Skopje) so "publish now" isn't future-dated; public site displays **Washington / US-Eastern** time (`display_timezone`); comment posting time editable in admin.
- **Push branding**: SW notifications use the TTD icon + monochrome badge + post image (was Android's default globe); admin **"Push to phones"** per-post manual send; branded **favicon** + PWA icons; functional header **Subscribe** button.
- **Analytics/Ads**: Google Analytics (`G-7SSS8SELE3`) + AdSense (`ca-pub-3345225040870333`) tags added (admin-managed settings). CSS/JS **cache-busting** via `?v=filemtime`. Trending card meta pinned to a fixed position.

## 2026-07-18 — AI comment auto-moderation (approve / reject / hold)
Comments are now read by the AI against the platform rules on submit. `CommentModerator` service (OpenAI, json_schema {decision, reason}): **approve** → live instantly, **reject** (hate/harassment/threats/sexual/spam/scams/illegal/doxxing) → hidden, **review** (borderline) → held for the admin. Prompt explicitly allows strongly-worded/partisan opinion (only real violations rejected), and appends optional custom `comment_rules`.
- Runs synchronously in `CommentController@store` (works without a queue worker; ~1-3s on gpt-5.4-mini). **Fail-safe**: no key / disabled / API error → 'review' (held for human), never blind auto-approve. Verdict + reason stored on `comments.ai_reason` + `moderated_at`; submit flash message reflects outcome (live / held / rejected).
- Settings: **Comment Moderation (AI)** section — `ai_moderation_enabled` toggle + `comment_rules` textarea. Admin CommentResource shows an AI/sparkles column + the verdict reason (tooltip + edit-form placeholder). Human can still override any verdict.
- Verified live on gpt-5.4-mini: strongly-worded partisan opinion → APPROVED; scam-link spam → REJECTED; dehumanizing hate speech → REJECTED (each with a correct reason). Test data removed.

## 2026-07-18 — Threaded replies in the forum (reply to a comment OR start a new one)
Added one-level threading to the discussion system. `comments.parent_id` (self-ref FK, cascade). A reply attaches to a comment; replies-to-replies flatten onto the top-level parent (`parent_id ?? id`) so display stays one level deep (mobile-friendly).
- Post `approvedComments` = top-level only, eager-loads `approvedReplies`; PostController loads `approvedComments.approvedReplies`. CommentController validates optional `parent_id` (must be a comment on the same post) and flattens.
- Front-end: each comment/reply has a "↩ Reply" button; replies render indented with an accent left-border + smaller avatar. A single shared form gains a hidden `parent_id`; small vanilla JS sets it on Reply (shows "Replying to X" banner, retitles heading + button to "Post reply", scrolls/focuses) with a Cancel that resets to a new top-level comment. Heading count now includes replies.
- Admin CommentResource marks replies (↩ / [reply]). Moderation/privacy unchanged — replies are pending until approved too.
- Verified end-to-end: top-level + nested reply render correctly; reply-to-reply flattened to the top-level; Reply-button JS sets/clears parent_id and labels (browser-confirmed). Test data removed; 3 Opinion posts remain comment-enabled.

## 2026-07-18 — Opinion category reworked into a discussion FORUM (not article comments)
Clarified intent: the user wanted the Opinion category to work like a forum — editor posts a prompt ("What do you think…?"), readers reply/discuss — NOT comments tacked onto news articles.
- **Opinion category page** (`categories/opinion.blade.php`, routed when `slug === 'opinion'`): a forum board listing each Opinion post as a discussion topic row — icon, question/title, snippet, "Started by … · ago", and a **reply count** ("N replies") with last-activity. CategoryController loads `withCount`/`withMax` on approved comments. Other categories keep the normal news-card layout.
- **Opinion post = thread**: the comment section (built earlier) reframes to "💬 Discussion (N)" + "open discussion" copy on Opinion posts; stays "Reader Opinions" elsewhere.
- **Comments stay Opinion-only**: `allow_comments` defaults on for Opinion (existing 3 Opinion posts backfilled), off for news; the reply engine/moderation/privacy from the comments build are reused unchanged.
- Verified in browser: forum board shows 3 topics with reply counts; opinion post shows the Discussion thread + reply form; news posts have no comments.

## 2026-07-18 — Reader comments ("opinions") with private data capture + moderation
Readers can post opinions on posts where comments are enabled. Public shows ONLY first name + surname + the comment; email + phone are captured and stored **admin-only**.
- **Per-post `allow_comments` toggle** (recommended over all-posts): editor toggle defaults on for Opinion category; AI-ingested posts open comments only for Opinion. Comments render on the article page only when enabled.
- **Form** (`partials/comments`): first name, surname, email (kept private), phone (kept private), opinion, required **consent checkbox** linking the privacy policy. Approved comments show as avatar-initial cards (name+surname, relative time, body).
- **Moderation (approve-first)**: `comments` table, status pending→approved/rejected/spam. `CommentController` stores as pending with a **honeypot** anti-spam field + `throttle:5,1` rate limit + ip hash. Filament **CommentResource** (Audience group, pending-count badge, approve/reject row + bulk approve, private email/phone visible to admin only) gated by `manage_audience`.
- **Privacy**: new "Comments & Reader Opinions" section in the Privacy Policy (what's collected, name/surname public, email/phone private, removal rights).
- **Verified end-to-end**: real comment stored pending (hidden publicly); honeypot spam silently dropped; after approval the public page shows "John Patriot" + body while **email/phone had 0 occurrences in the HTML** (browser-confirmed). Test data removed; no posts left with comments enabled.
- Flagged to user: requiring phone deters commenters + adds PII liability (built as requested; easy to make optional).

## 2026-07-18 — Footer socials: X, Facebook, YouTube, Truth Social, Telegram
Footer social row now has 5: added Truth Social (glyph "T") and kept Telegram but swapped its plain ✈ emoji for the proper Telegram logo (inline SVG, currentColor, 17px — matches the other icons' box/hover style). Links are still `href="#"` placeholders pending the user's real profile URLs.

## 2026-07-18 — Article share rail: X, Facebook, Truth Social, Telegram, copy-link
Single-post share rail now has all five. Truth Social uses `https://truthsocial.com/share?text=<title>&url=<link>` (confirmed via help.truthsocial.com official docs). Telegram re-added with `https://t.me/share/url?url=<link>&text=<title>` and the proper Telegram logo SVG (matching the footer). X/Facebook/copy-link unchanged. Verified hrefs URL-encoded; rail renders 𝕏 / f / T / Telegram / copy in the circular buttons.

## 2026-07-18 — Trending placement (manual pin + AI), completing the 3-signal system
Extended the breaking/top pattern to Trending. The three signals are now independent editorial axes: **breaking** = urgency (12h expiry), **top story** = importance (hero), **trending** = popularity/virality (48h expiry).
- New `is_trending` + `trending_until` (migration `add_trending_to_posts_table`); `scopeTrendingActive` + `is_trending_now` accessor mirror breaking.
- **AI**: Rewriter schema/prompt now also returns `is_trending` (viral/high-engagement/buzz — distinct from importance); IngestService sets it with a 48h expiry. Verified the axes are independent: a viral celebrity-wedding story → trending TRUE but breaking/top FALSE; routine council story → all FALSE.
- **Manual**: "Trending now" toggle + "Trending until" datetime in the editor (auto-defaults 48h); table fire-icon column + ternary filter.
- **Homepage Trending strip** (`HomeController`): pinned/AI-trending stories first, then fill by view count — verified a pinned 1-view post jumps to #1 (with a 🔥 flame) ahead of 24k-view posts. Previously pure view-count.

## 2026-07-18 — Top-story + breaking-news placement (manual + AI-decided)
Two editorial placements, settable by hand at post time AND chosen automatically by the AI on ingest.
- **Top story** = existing `is_featured` (hero slider), relabeled "Top story (hero slider)" in the editor. **Breaking** = new `is_breaking` + `breaking_until` (auto-expiry) → drives the top ticker.
- **AI classification**: Rewriter's JSON schema extended with `is_breaking` + `is_top_story`; the editor prompt classifies each ingested story CONSERVATIVELY (breaking only for urgent major events; top only for nationally significant). IngestService applies them (breaking sets `breaking_until = now + 12h`). Verified: mock major-earthquake story → both true; "library extends hours" → both false.
- **Manual controls** in the post editor Publish section: Top-story toggle, Breaking toggle (auto-defaults a 12h expiry when switched on), and a "Breaking until" datetime. Table gains Top/Breaking icon columns (breaking = red bolt) + ternary filters.
- **Ticker** (`partials.ticker` composer): shows active-breaking stories first (label reads "BREAKING"), falls back to latest headlines (label "LATEST") so it's never empty; items now link to the post, breaking ones get a red dot. `Post::scopeBreakingActive` + `is_breaking_now` accessor honor the expiry. Article page shows a red "⚡ Breaking" badge while live.
- Verified end-to-end in browser/curl: label flips BREAKING↔LATEST correctly, article badge renders, expiry respected. Migration `add_breaking_to_posts_table`.

## 2026-07-19 — Fixed double watermark (retired the baked-in pixel watermark)
On the server, `watermark_enabled` was '1', so the old baked-in TEXT watermark was stamped into the pixels on top of the CSS `.img-logo` overlay → two watermarks. Fix: **removed the pixel-watermark entirely** (deleted the `stampWatermark` call + method in `ImageService`, and its admin settings) — the brand mark is the CSS overlay only, so a double watermark is now structurally impossible ("solve it for the future"). Regenerated clean variants for all 18 server images from their (clean) originals; AI-vision confirmed no baked watermark remains, colored logo still renders. The `ImageAuditor` vision tool remains available to spot stray watermarks on demand.

## 2026-07-18 — Watermark reworked into a header-style logo OVERLAY (never clipped)
User: the baked-in text watermark got cut on smaller cards (center-crop shaves image edges), and wanted it to look like the header logo. Switched from baked-in pixels to a **CSS overlay** pinned to the image container's top-left — so it tracks the VISIBLE corner and can never be cropped, and renders crisp like the real logo.
- `.img-logo` overlay = red gradient **TTD** square badge + **"The True Defender"** wordmark (Defender in `--accent-2` red), matching the header. Added to `partials/postimg` (badge+wordmark on card/hero, **badge-only on tiny thumbs**) and the article hero (larger variant). New CSS in style.css.
- Disabled the baked-in pixel watermark (`watermark_enabled = 0`) and regenerated variants clean, so the two don't double up.
- Verified in browser: category cards + related-story cards show the full logo top-left, uncropped; hero shows the larger logo; no clipping anywhere.
- Trade-off noted for user: a CSS overlay shows on-site but does NOT travel with a downloaded/hotlinked raw image (the baked-in one did). Can re-add a crop-safe baked mark for share-protection if wanted.

## 2026-07-18 — Own-brand watermark on post images
After removing source watermarks, added TheTrueDefender's OWN watermark to images.
- Bundled a portable open font in the repo (`resources/fonts/brand.ttf`, DejaVuSans-Bold via jsDelivr — works on the Linux server too; project has no logo asset, nav mark is CSS).
- `ImageService::stampWatermark()` overlays semi-transparent brand text (white ~70% + soft shadow) bottom-right on every variant during `makeVariants()`, size scaled to each variant (~3.2% of width). Runs for all image paths (AI-generated, uploaded).
- Settings: **Image Watermark** section in AI & Ads Settings — `watermark_enabled` toggle + `watermark_text` (defaults to app name). Fixed a Setting-model gotcha: booleans now stored as '1'/'0' (empty string would fall back to default), with `filter_var` reads on both ends.
- Re-stamped all 7 existing post images; vision confirmed the Vikram hero now reads "TheTrueDefender bottom-right". Browser-verified (subtle on the article hero due to the bottom scrim gradient; clearer on cards/share images — opacity is adjustable in `stampWatermark` if wanted).
- Guidance in the settings UI: use the watermark on your own/AI images only, not on copied press photos.

## 2026-07-18 — Watermark detection: AI vision flags source-branded photos, regenerates them
User flagged the Vikram-1 post photo carried a baked-in "BBC NEWS" logo (from the earlier og:image backfill). Built AI vision detection + replacement.
- **`ImageAuditor` service**: sends an image to the vision model (gpt-5.4-mini, confirmed multimodal) asking for watermark/logo/network branding (JSON schema `{branded, detail}`); if branded, **regenerates an original AI image** via `ImageService::generate()` from the post title, swaps it in, rebuilds hero/card/thumb variants, deletes the old file. Fail-safe: any API error → treated as "not branded" so images are never destroyed on a hiccup.
- **Deliberately NOT de-watermarking**: erasing another outlet's logo to republish their copyrighted photo is a worse copyright problem than leaving it, and leaves artifacts — so we regenerate instead.
- **`images:audit` command** (`--fix`, `--source-only`): report or replace. Going-forward is already handled (feed `ai_image` = ON generates originals; gpt-image-2 fixed earlier).
- **Verified**: vision correctly read "BBC NEWS logo in the bottom-left corner"; all **7 source-copied posts cleaned** (6 regenerated, Vikram replaced then re-confirmed clean); new Vikram image is an on-topic rocket-launch original, watermark-free (browser-confirmed). Old BBC files deleted.

## 2026-07-18 — AI internal + authoritative external links (validated, no broken links)
Posts now get contextual links added by AI, with a hard validation layer so broken/hallucinated links are impossible. Deliberately NOT done via the "Teach the AI" prompt (the model doesn't know real URLs and would invent broken ones).
- **`LinkEnricher` service**: AI wraps existing phrases in links (no wording changes) — 2-4 **internal** links chosen from a list of the post's REAL related/recent published posts, plus up to 3 **external** links to authoritative refs (English Wikipedia / .gov). Then `sanitize()` validates every `<a>`: internal must resolve to an existing post/category slug; external must be an allowlisted domain (wikipedia.org / .gov) AND return HTTP 200 (capped at 5 checks, fail-closed). Anything else is unwrapped to plain text. Internal hrefs normalized to root-relative (`/post/slug`) so they survive domain changes.
- **Integrated into `SeoOptimizer::optimizePost`** (runs before scoring, so links boost the SEO "Links" check): covers AI-ingest, admin post creation (`afterCreate`), the bulk "Optimize SEO" action, "AI-optimize all posts" button (= backfill for existing posts), and `seo:optimize`. Idempotent — skips posts that already have internal links.
- Cost: one extra small AI call per post (~$0.005 on gpt-5.4-mini). Internal links are naturally sparse on a small catalog and grow as more posts exist.
- **Verified live**: Vikram-1 post got 2 validated Wikipedia links (Vikram Sarabhai, Rocket Lab, both HTTP-200-checked); an Iran post cross-linked to 2 real sibling posts; sanitize kept a real internal link while stripping a fake internal, a dead Wikipedia page, and a non-authoritative blog. (2 posts enriched during testing; rest backfill via the "AI-optimize all posts" button.)
- Teaching-AI field stays for tone/style ONLY — do NOT add link instructions there.

## 2026-07-18 — Removed public source-attribution line from posts
Per user request, removed the "Reporting based on {source}. Summary written by TheTrueDefender." block from the single-post page (`posts/show.blade.php`). Posts are now full original rewrites so the user opted to present them as wholly original. **Kept `source_name`/`source_url` stored internally** — they're still used for dedup (GUID + og:image fetch), the admin Ingest Log "Open post"/source link, and the Post form's (collapsed) Source attribution section. Only the front-end display was removed; nothing else references it publicly. Note: attribution was part of the original "legal-safe ingest" design — facts aren't copyrightable so original rewrites without attribution are defensible, but this is an editorial/legal call the user owns.

## 2026-07-18 — Image-model compatibility + cost review
User switched models in settings to `gpt-5.6-sol` (text) and `gpt-image-2` (image) and enabled AI images. Found & fixed: `gpt-image-2` rejects dall-e-3 parameters (400 "Unknown parameter: response_format") — `ImageService` now adapts per model family (dall-e-* → 1792×1024 + response_format; gpt-image-* → 1536×1024, base64 default). Verified live: real 1536×1024 image generated + hero/card/thumb variants created (test files removed). Cost note (official pricing, Jul 2026): gpt-5.6-sol is flagship-tier ($5/$30 per 1M in/out ≈ $0.10 text cost per article vs ~$0.015 on gpt-5.4-mini); at current volume (~7 posts/day, AI images on) ≈ $45-50/mo — mini-tier text model recommended for news rewriting.

## 2026-07-18 — Fix: 500 "Maximum execution time of 30 seconds exceeded" on Optimize with AI
Two compounding causes behind the Livewire crash in the post editor:
1. A real AI analysis takes ~16s and the optimize flow runs two (analyze → apply meta → re-score) ≈ 32s+, over the dev web server's 30s PHP limit. Fix: `set_time_limit()` reset inside every OpenAI call site (SeoAnalyzer 180s, Rewriter 180s, Deduplicator 180s, ImageService 300s) so the clock restarts per call.
2. While the account was out of credits, the retry logic pointlessly retried `insufficient_quota` 429s, adding dead time. New `App\Support\OpenAiRetry::when()` policy shared by all four services: retry transient failures (connection errors, 401/408/429/5xx) but **fail fast on insufficient_quota**.
Verified: `optimizePost` on the exact crashing post (#37) now completes in 46s without a timeout — real AI, score 27 → 68, meta description + focus keyword filled. (Also confirmed the user's credits are active again — analysis runs on the `openai` engine.)

## 2026-07-18 — Follow-up: "asks for key although key is set" = OpenAI account out of credits
User saw "enter the key" when pressing Optimize with AI. Diagnosis: the key is valid (HTTP 200 on /v1/models) but chat completions return **429 `insufficient_quota`** — the OpenAI account ran out of credits (it worked earlier the same day until the balance was consumed). The fallback summary text wrongly said "Add an OpenAI key…" for ANY failure.
- Fix: `SeoAnalyzer` now reports the real cause in the score panel — out of credits (with billing link), rate-limited, invalid key, or network error. The Rewriter stub note was equally misleading and now points at key + credits.
- **User action required: add funds at platform.openai.com → Settings → Billing.** All AI features (rewrites, images, SEO, dedup embeddings) resume automatically once credits exist — no code/config change needed.

## 2026-07-18 — Auto SEO optimization with AI (site-wide, per-post, and on-create)
SEO went from "analyze & suggest" to **optimize**: the AI now APPLIES its suggested meta (title/description/focus keyword) and re-scores afterward so the stored score reflects the optimized state.
- **`SeoOptimizer` service**: analyze → apply suggested meta → re-analyze for an honest score. Two modes: blank-fill (default; never clobbers hand-written meta — used by all bulk/auto paths) and overwrite (`--force`).
- **Whole site at once**: `seo:optimize` command (`--pages`, `--posts`, `--missing-only`, `--force`) + admin header buttons — **"AI-optimize all pages"** (Page SEO list) and **"AI-optimize all posts"** (Posts list) — which queue an `OptimizeSeoJob` per record (needs the queue worker).
- **Post-per-post**: the editor button is now **"Optimize with AI"** — fills the meta fields in the form (still editable before saving) + shows the score; the bulk table action does the same for selected posts.
- **Automatic on create**: admin-created posts are optimized in `CreatePost::afterCreate` (with a score notification); AI-ingested posts were already optimized inline (now via the shared optimizer).
- Fixes found while verifying: local `APP_URL` was `http://localhost` (dev server is `127.0.0.1:8010`) so live-page fetches failed — corrected in `.env` (production sets its own; also fixes affiliate referral links locally). Layout/post titles no longer double-brand when the AI meta title already contains "TheTrueDefender".
- **Verified with real AI**: all 6 static pages optimized (home 67, about 72, privacy 78, terms 68, contact 56, shop 55 — up from meta-only low-40s), live homepage `<title>`/description now serve the AI meta; single-post optimize took the Vikram-1 story 43 → 68 with meta applied; full-posts run executed across the whole catalog.

## 2026-07-18 — Embedded on-page card checkout (Stripe Elements/Payment Element)
Per user choice, replaced the hosted-redirect Stripe flow with an ON-PAGE card form (Stripe Elements) so customers pay without leaving /checkout.
- `StripeService`: added `publishableKey()`, `createPaymentIntent()`, `updatePaymentIntent()`, `paymentIntentSucceeded()`; `isConfigured()` now needs BOTH publishable + secret keys. New `stripe_key` (publishable) setting.
- Flow: `checkout()` creates a PaymentIntent for the cart total → passes client_secret + publishable key to the view (id in session). Checkout page loads Stripe.js, mounts the Payment Element; on submit, JS POSTs shipping to `place()` (JSON) which creates the order + attaches it to the PaymentIntent, returns a return_url; JS then `stripe.confirmPayment()`. `success()` verifies the intent succeeded → marks paid → clears cart. Webhook now handles `payment_intent.succeeded`. COD fallback unchanged when Stripe not configured.
- Card data goes straight from the browser to Stripe (never our server). Verified: checkout renders HTTP 200 (COD mode, no keys); all code lints.
- **BLOCKED on user**: the card form only appears once the user enters their Stripe publishable + secret keys (Admin → AI & Ads Settings → Payments). Cannot be tested here without the user's Stripe test keys. Steps in DEPLOY.md §11 (to update for Elements + publishable key).

## 2026-07-18 — Shop relabeled "Free Gifts" (lead with FREE, mission as subline)
Per user preference, changed shop framing from "Support the Mission" to "Free Gifts" (advised: lead with the FREE hook — higher-converting + clearer than vague "Support" — keep the journalism-support line as the reason). Nav "🎁 Free Gifts", heading "Free Patriot Gifts" ("Yours free — you just cover shipping"), intro/checkout/footer updated. Model unchanged: items free (price 0) + shipping via Stripe. Flagged FTC/AdSense note: keep shipping transparent & near real cost (we show "+ $X shipping" clearly), don't pad it to hide profit.

## 2026-07-18 — Shop repositioned as "Support the Mission" (free item + pay shipping via Stripe)
Reframed the shop as a support model per user: items are free thank-you gifts, supporter covers shipping only, paid by card through Stripe (uses the free-plus-shipping + Stripe Checkout built earlier).
- Copy/nav: homepage section + shop page now "Support the Mission" with supportive intro ("item is on us — you only pay shipping, securely by card through Stripe"); nav "🛒 Shop" → "🇺🇸 Support"; footer link → "Support the Mission"; checkout intro reframed as a thank-you.
- Converted the 8 sample products to the support model (price 0 + shipping_price $5.95–8.95) so cards match the copy — each shows green **FREE** + "+ $X shipping".
- Verified: cart math correct (2 free caps → $0.00 items + $11.90 shipping = **$11.90 charged, shipping only**). Card charging goes live once the user adds Stripe keys (Admin → AI & Ads Settings → Payments; currently NO → COD fallback); Stripe Checkout is hosted so the card is entered on stripe.com and funds go straight to the user's Stripe account. Steps in DEPLOY.md §11.

## 2026-07-18 — Shop: free-plus-shipping products + Stripe card payments
Closes the deferred "payment provider" item. Two features, per user request:
- **"FREE — just pay shipping" products**: new `products.shipping_price` (per item). Price 0 → shop cards and product pages show a green **FREE** badge + "Just pay $X shipping & handling". Cart/checkout now compute and display **Subtotal / Shipping / Total** (shipping = per-item price × qty); orders store the real shipping+total (was hardcoded 0). Admin: Pricing section gains the Shipping & handling field ("price 0 = free product" hint); Products table shows FREE + shipping column.
- **Stripe payments** via hosted **Stripe Checkout** (no SDK — raw API like GSC; card data never touches the server, SAQ-A PCI): `StripeService` creates a Checkout Session (free items listed at $0 + a dedicated "Shipping & handling" line), redirects the customer to stripe.com, and marks the order paid two ways — the **success return page** (session re-verified server-side) and the **signed webhook** `/stripe/webhook` (checkout.session.completed; HMAC-SHA256 signature verification, 5-min replay window, CSRF-exempt). Cart is kept until payment succeeds, so a cancelled Stripe payment loses nothing. Keys in Admin → AI & Ads Settings → **Payments (Stripe)** (env fallback `STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`); **blank keys = existing cash-on-delivery flow unchanged**.
- DB: `products.shipping_price`; `orders.payment_method` / `stripe_session_id` / `paid_at`. Admin Orders table shows a Paid/Unpaid badge; confirmation page reflects paid state. Affiliate commissions unchanged (computed on order total incl. shipping).
- **Verified end-to-end**: free test product ($0 + $4.95 shipping) → FREE on product page → add-to-cart ×2 → cart/checkout showed $0.00 / $9.90 / $9.90 → COD order stored correctly, stock decremented → then simulated Stripe webhook with a **valid HMAC signature marked the order paid (200)** while a tampered signature was rejected (400). Test data removed. Real-card flow needs the user's Stripe keys (test mode steps in DEPLOY.md §11).

## 2026-07-18 — Image quality pipeline: high-res sources + per-placement size rules
User reported BBC photos looked "extra bad" — root cause: feeds deliver **240×135 RSS thumbnails** that were being stretched across full-width cards.
- **High-res source fetch**: `ArticleFetcher::extract()` now also pulls the article page's **og:image / twitter:image** (BBC serves 1200×675); ingest prefers it over the RSS thumbnail.
- **Quality gate**: `ImageService` rejects sources narrower than **500px**; if og:image AND RSS image both fail, the pipeline **falls back to an AI-generated original** (dall-e-3, now widescreen **1792×1024**). Deliberately NOT AI-altering real news photos (fake-imagery/editorial risk) — AI only replaces unusable ones.
- **Per-placement size rules** (16:9 center-crop JPEG variants generated via GD on every stored image): **hero 1600×900** (homepage slider + article hero), **card 800×450** (category feature/overlay cards, related grid, archives), **thumb 400×225** (list rows, mini thumbs). Never upscales past the source. `Post::imageUrl($size)` + `partials/postimg` `size` param wire each template slot to its variant; `PostObserver` backfills variants for admin-uploaded images too.
- **Reworked all 7 existing ingest posts**: every 240px thumbnail replaced with the real 1200×675 BBC photo (og:image — AI fallback not needed), old tiny files deleted, variants generated.
- Verified visually: article hero sharp full-width, homepage row thumbs crisp; variant URLs serve (card = ~45KB vs old 6KB thumbnails); social-share meta keeps the original image.
- Note: BBC og:images carry a small "BBC NEWS" corner watermark (authentic source photos; attribution card already credits them). For fully unbranded art, flip the feed's "Generate original image with AI" toggle ON.
- **Follow-up fix — cards showed no photo at all** (user report, `/category/world`): long-standing CSS bug, not the pipeline. `.img-ph` (defined later in style.css) overrode `.story-bg`'s `position:absolute` with `relative`, collapsing the image container to **height 0** on every overlay/feature/related card (emoji placeholders were equally invisible there; homepage rows escaped via flexbox). One-line fix: `.story-bg.img-ph { position: absolute; }`. Verified in-browser: container 449×298, all card photos render.

## 2026-07-18 — Affiliate program (traffic revenue share + shop commissions)
The platform is now an affiliate platform: affiliates promote the blog and products via personal referral links and earn a share of the value they bring.
- **Model (AdSense-safe)**: affiliates are paid for **referred VISITS, never ad clicks** (paying for ad clicks = AdSense ban). Default: you earn ~$6 RPM → affiliate gets 70% = **$4.20 per 1,000 valid visits**, plus a **10% commission** on shop orders from their visitors (30-day cookie). All rates are global settings (AI & Ads Settings → Affiliate Program) with **per-affiliate overrides**; user sets commissions manually per choice.
- **Tracking**: `?ref=CODE` works on ANY page (`TrackAffiliate` middleware) → `AffiliateProgram` service validates each visit — bot UA filter, 24h ip+ua dedup, max 3 valid clicks/IP/day — invalid clicks stored with reasons (bot/duplicate/ip-rate-limit) for forensics. Checkout reads the attribution cookie → `affiliate_conversions` (pending → approved → paid).
- **Affiliate portal** (`/affiliate`, own auth guard, site-styled Blade): apply page (`/affiliate/apply`, terms with explicit "never click ads" clause, applications start **pending until admin approval**), login, dashboard (referral link + copy button, valid clicks all-time/30d, traffic earnings, sale commissions, paid, balance, rates display, conversion + payout history). Footer link "Become an Affiliate".
- **Admin**: Audience → **Affiliates** resource (pending-count badge, approve action, per-affiliate rate overrides, stats, **Record payout** action that also settles approved commissions), gated by new `manage_affiliates` permission.
- **DB**: `affiliates`, `affiliate_clicks`, `affiliate_conversions`, `affiliate_payouts`. New settings: `affiliate_rate_per_1000` (6), `affiliate_share_pct` (70), `affiliate_sale_commission_pct` (10), `affiliate_cookie_days` (30).
- **Verified end-to-end**: 6 simulated visitors → exactly 3 valid + duplicate/bot/rate-limit flags; cookie set; $50 order → $5 commission; payout zeroed balance; affiliate login + dashboard render live; pages HTTP 200. Test data removed.
- **Payouts are manual** (admin records them; pay via PayPal/bank yourself). Automated payouts + a public affiliate leaderboard are possible later add-ons.
- **Rate lock-in (follow-up, same day)**: per user request the RPM is fully custom (global setting + per-affiliate override, changeable any time). Added `affiliate_clicks.earnings` — each valid click **locks in the payout at that moment's rate**, so changing rates later never re-prices already-earned balances (verified: click at $6/70% kept $0.0042 after rates changed to $9/80%; new click earned $0.0072).

## 2026-07-18 — Fixes: full-article AI rewrites, transient-401 retries, "Open post" 404; diagnosed 5-min scheduler
User reported: no 5-min pulls, "Open post" 404s, AI not writing full content / not auto-publishing. Root causes + fixes:
- **Full-article rewrites**: new `ArticleFetcher` service downloads the source article page and extracts the main text (prefers `<article>`/`<main>`, then densest `<p>` cluster; 400–9000 chars; boilerplate filtered). `Rewriter` now writes a **complete 350–600-word original story** when full text is available (RSS-snippet mode stays 120–220 words so nothing is invented). Wired into `IngestService` before each rewrite.
- **Transient OpenAI 401s** were making rewrites silently fall back to the stub (unchanged title, ~35-word body). The key itself is valid (HTTP 200 check) — added `retry(2, 1000)` + key trim to ALL OpenAI calls (Rewriter, SeoAnalyzer, Deduplicator embeddings, ImageService).
- **"Open post" 404 in Ingest Log**: the action built the edit URL from `post_id` (numeric) but post routes bind by slug → fixed to pass the Post model.
- **Not auto-publishing**: earlier posts were created while auto-publish was OFF (flag only applies at creation time) and/or fell to stub on the 401s. Reprocessed all 6 stub/short drafts through the new pipeline — all now **published** with 412–520-word articles, fresh AI headlines, auto-SEO + meta.
- **No 5-minute pulls — not a bug**: neither `schedule:work` nor `queue:work` was running on the dev machine (only the web server). The automation needs all three processes → double-click `run-local.bat` (Web + Queue + Scheduler windows must stay open). Note: 84 queued push/social jobs will flush when the queue worker starts.
- **Verified live end-to-end**: a brand-new BBC story ingested during testing came out as a published 466-word original article ("Vikram-1 Launch Puts India's Skyroot in Orbit") with auto-SEO (43), auto-filled meta, and a stored dedup embedding — zero manual steps.

## 2026-07-18 — Full pipeline automation: cross-feed duplicate detection + auto-SEO on ingest
Closed the last open TODO (#5). The 5-minute ingest now runs the complete chain unattended: scan feeds → **skip duplicate stories** → AI rewrite → auto-fill SEO meta + score → publish → social push.
- **Cross-feed duplicate detection** (`Deduplicator` service): before the (paid) AI rewrite, each incoming item is compared against the last 7 days of ingested items across ALL feeds. Hybrid matching — **OpenAI embeddings** (`text-embedding-3-small`, cosine similarity, threshold setting `dedup_threshold` default 0.85, tunable in AI & Ads Settings) when both sides have vectors; **fuzzy title similarity** (≥78%) for older/keyless items. Duplicates get status `duplicate` in the Ingest Log (info badge, tooltip shows which story it matched, new filter option) and are skipped before any API cost. Embedding stored per item (`ingested_items.embedding`).
- **Auto-SEO on ingest**: every AI-created post is analyzed by `SeoAnalyzer` immediately after creation — score + checklist stored, and blank meta title/description/focus keyword are auto-filled from the AI suggestions. The admin SEO column now fills itself; failures never block the post.
- Threshold is deliberately conservative (prefer publishing when unsure) so follow-up stories ("…seventh night" vs "…eighth night") aren't wrongly killed; lower `dedup_threshold` for stricter dedup.
- **Verified live against BBC World** (the user's real OpenAI key is active in Settings — discovered during testing; rewrites and embeddings are real now, not stubs): a second feed pointing at the same RSS produced `duplicate` statuses for already-ingested stories, a genuinely new story passed through and was rewritten + auto-scored (drafts got seo_score 18–30 and AI meta), and a semantic embedding match (0.868 cosine) was confirmed in the logs. Found & fixed a cold-start bug where semantic mode skipped the title fallback for pre-embedding items. All test artifacts (temp feed, items, drafts, temp admin) removed.
- Note: "teach the AI" only shapes writing; pipeline behavior (scanning, posting, dedup, SEO) is code — no second teaching field needed.

## 2026-07-18 — SEO rank visibility: AI on-page score + real Google ranking (per post & page)
Every post and every page now shows its SEO standing in the admin — two complementary signals:
- **AI on-page SEO score (0–100)**: new `SeoAnalyzer` service computes deterministic local signals (title/meta length, focus-keyword usage in title/intro/URL, density, word count, H2s, image alt, links, Flesch readability) and sends them to **OpenAI** (json_schema) for a grounded score + pass/warn/fail checklist + prioritized fixes + suggested meta. Falls back to a pure local heuristic when no API key. Stored on the record (`seo_score`, `seo_analysis`, `seo_analyzed_at`).
- **Real Google ranking (Search Console)**: self-contained `SearchConsole` client (service-account JWT signed via openssl — no google/apiclient dep) → `seo:pull-rankings` command maps GSC page metrics to posts/pages by URL and stores **avg position / clicks / impressions / CTR** (`gsc_*`). Scheduled daily; dormant until credentials are set (like AdSense/social). Configured in AI & Ads Settings (property + service-account JSON).
- **Admin UI**: color-coded **SEO score badge column** + **Google #** column on the Posts list (sortable; "Poor SEO" / "Not analyzed" filters), an **SEO panel** in the post editor with an "Analyze with AI" button (live score gauge + checklist) and meta-title/description/focus-keyword fields, a **bulk "Analyze SEO"** action, and a new **Content → Page SEO** screen for the 6 static pages (home/shop/about/contact/privacy/terms).
- **Frontend (completes the old TODO #4)**: layout now emits canonical, Open Graph + Twitter cards, and posts output **JSON-LD `NewsArticle`** schema; per-post meta title/description override the defaults, and static-page meta is admin-managed via a `layouts.app` view composer (`page_seo` table + `PageSeo` model).
- Verified end-to-end in the real admin: Posts list shows the SEO/Google columns, the editor's "Analyze with AI" produced a live score + checklist, Page SEO lists all pages, and the public post page renders the new meta/OG/JSON-LD (HTTP 200). Ran on the local heuristic since `OPENAI_API_KEY` is blank — set the key to switch to AI. **Still open from that round:** (5) cross-site duplicate detection.
- DB migrations: `add_seo_to_posts_table`, `create_page_seo_table`. New env: `GSC_PROPERTY`, `GSC_SERVICE_ACCOUNT_JSON`. Setup steps in `pulse/DEPLOY.md` §10.

## 2026-07-18 — Admin control center (ads, AI settings, roles) + partial for SEO/dedup
Turned admin into a control center. Built 3 of 5 requested items this round:
- **Ad placements manageable from admin**: `ad_placements` table + `AdPlacementResource` — each of the 4 slots (home, article-mid, article-end, category) has its own settings (enable/**hide** toggle, format, AdSense slot ID, or custom ad code). The `partials/ad.blade.php` now renders from the DB per placement.
- **AI & Ads Settings page** (`ManageAiSettings`): OpenAI API key, text model, image model, a **"Teach the AI" house-style/instructions** field (injected into every rewrite), and the global AdSense publisher ID — all stored in a cached `settings` table. Rewriter + ImageService now read these settings (env fallback).
- **Admins & Roles**: `UserResource` (create/edit admins, assign roles, set password) + `RoleResource` (create roles with a checklist of permissions = "custom rules"). Permissions seeded (manage_posts/shop/audience/automation/ads/settings/users); `User` implements `FilamentUser::canAccessPanel` (needs a role); `Gate::before` gives the **admin** role everything. Sensitive admin areas gated by permission.
- Fixed a caching bug (was caching an Eloquent Collection in the DB cache → unserialize 500 on the category page); switched to per-request memoization.
- **STILL TO DO (next round):** (4) AI-driven **SEO optimization** (AI-generated meta title/description/keywords, Open Graph + Twitter cards, JSON-LD Article schema, sitemap.xml), and (5) **cross-site duplicate detection** (skip publishing near-duplicate stories seen from multiple feeds, via title/semantic similarity or embeddings). "Teach AI to work with social channels" is partly covered by the AI instructions field; deeper per-channel tone can extend it.

## 2026-07-18 — Article page redesign + Google AdSense readiness
- Rebuilt the single-post page (it previously had **no CSS at all**): cinematic full-width hero (image or category art + scrim), breadcrumb, big headline, author avatar + date + reading time + views, sticky share rail (X/Facebook/Telegram/copy-link), lead paragraph with accent bar, drop cap, styled body typography (headings/blockquotes/links/images), source-attribution card, related-stories grid, and a reading-progress bar.
- **AdSense integration (renders only when configured):** `partials/ad.blade.php` ad-slot component labeled "Advertisement" with reserved height (no layout shift); AdSense loader script in the layout head; slots placed **mid-article** (in-article fluid format, body auto-splits at a paragraph boundary), after the article, on the homepage between sections, and on category pages. Dev mode shows labeled placeholders.
- Policy prep: `public/ads.txt` placeholder, Privacy Policy "Advertising" section (Google cookie disclosure + opt-out links), ads clearly labeled and spaced from UI.
- Config: `ADSENSE_CLIENT`, `ADSENSE_SLOT_ARTICLE`, `ADSENSE_SLOT_DISPLAY` in `.env` (blank until Google approval).
- New Post helpers: `reading_minutes`, `bodyParts()` (mid-split for the in-article ad). JS: reading progress + copy-link.

## 2026-07-17 — Header menu now links to category pages
- The top nav category links used to jump to homepage anchor sections (`/#politics`); they now navigate to the full category archive pages (`/category/politics`, …), matching standard news-site behavior.
- The current category is highlighted in the nav when you're on its page.

## 2026-07-17 — News Feeds: image support
- Feeds are already auto-checked every 5 min → new items → OpenAI rewrite → auto-publish (if the feed's toggle is on). Added **image handling** to complete this.
- `FeedReader` now extracts an image per item (Media RSS `media:content`/`media:thumbnail`, `<enclosure>`, or first inline `<img>`). Fixed a SimpleXML namespaced-attribute bug (`->attributes()->url`).
- New `ImageService`: downloads a source image into `storage/app/public/posts/` (type/size-guarded), or generates an **original image via OpenAI** (`dall-e-3`, `PULSE_AI_IMAGE_MODEL`).
- Ingest source "Add images" toggle (`fetch_images`) now works, plus a new **"Generate original image with AI"** toggle (`ai_image`): ON = OpenAI creates an original image (safer); OFF = copy the source photo.
- Frontend now renders `featured_image` (with emoji-gradient fallback) on the hero slider, feature/overlay/rows category cards, article hero + related, and category archives — via a shared `partials/postimg.blade.php`.
- Verified: enabled copy-images on the BBC feed → re-ingest downloaded real photos → posts got `featured_image`, files serve over HTTP (200), and `<img>` tags render on the site.

## 2026-07-16 — Added admin user
- Created a Filament admin user (`trajce_angelovski@hotmail.com`) with the `admin` role; login verified.

## 2026-07-16 — Added local run script
- Created `run-local.bat` at the project root: double-click to start the web server, queue worker, and scheduler (each in its own window) and open the site. Uses the portable PHP at `C:\Users\Angjelovski-PC\php\php.exe`.

## 2026-07-16 — Rebrand to "TheTrueDefender"
- Renamed the site from "The Daily Pulse" to **TheTrueDefender** across the live app.
- Logo: mark **TTD**, wordmark **The True *Defender*** (nav + footer).
- Updated all page titles, meta description, breaking-ticker welcome text, footer copyright, About/Privacy/Terms body text, and `APP_NAME` in `.env` + `.env.production.example`.

## 2026-07-16 — Phase 6: Dockerize + Hetzner deploy
- `Dockerfile` — PHP 8.3-FPM image with all required extensions (pdo_pgsql, pgsql, gd, zip, intl, gmp, bcmath, exif, pcntl, mbstring, opcache + redis).
- `docker-compose.yml` — full stack: **app** (php-fpm), **web** (Caddy, automatic HTTPS), **db** (PostgreSQL 16), **redis**, **queue** worker, **scheduler**.
- `Caddyfile` (auto-TLS), `docker/php/php.ini` (production), `docker/entrypoint.sh` (installs deps, waits for DB, migrates, publishes Filament assets, caches config).
- `.env.production.example`, `.dockerignore`, and **`DEPLOY.md`** step-by-step guide.
- Queue worker + scheduler run as always-on services, so push, social posting, and the 5-minute AI ingest all work in production with no cron setup.
- Verified locally: compose YAML parses, entrypoint shell syntax valid, extensions cross-checked against `composer.lock` (caught + added mbstring), app is production-cacheable. Actual image build happens on the Hetzner server (Docker not installed on the dev machine).

## 2026-07-16 — Phase 5: Social auto-posting
- Pluggable per-channel drivers in `app/Services/Social/`: **Telegram** (fully working), **X/Twitter** (API v2 + OAuth 1.0a signing), **Facebook Page** (Graph API), **Instagram** (2-step publish; requires a real image), **Truth Social** (best-effort via Mastodon-compatible API).
- `SocialManager` registry; `SendSocialPosts` job fires on publish (alongside web push) and logs each send.
- Admin: **Social Channels** (per-driver credential fields, active toggle) + **Social Log** (read-only, sent/failed + links).
- DB: `social_channels`, `social_posts`, `posts.social_posted_at`.
- Verified end-to-end: publishing a post dispatched the job → real HTTPS call to Telegram → graceful failure logged on a dummy token (real delivery needs real credentials).

## 2026-07-16 — Phase 4: AI news ingest (switched to OpenAI)
- RSS/Atom feed reader → **OpenAI** rewrites each item into an original summary in the site's voice (structured JSON output) with source attribution → saved as draft or auto-published per feed.
- Legal-safe: drafts by default, original wording + attribution, source-image copying off by default (all per-feed toggles).
- `ingest:run` command + scheduler runs it **every 5 minutes**; dedup by feed GUID; graceful stub fallback when no API key.
- Admin: **News Feeds** (feed config, auto-publish + copy-images toggles, "Run now") + **Ingest Log**.
- DB: `ingest_sources`, `ingested_items`. Config: `OPENAI_API_KEY`, `PULSE_AI_MODEL` (default `gpt-4o-mini`).
- Verified against the live BBC World feed (created draft posts with attribution; dedup confirmed).
- Note: originally built on Anthropic, then **switched to OpenAI** at the user's request (Anthropic SDK removed).

## 2026-07-16 — Phase 3: Audience (consent, subscribers, web push)
- Cookie consent banner (Accept ties into notification opt-in), email subscribers (footer/newsletter/popup forms), subscription popup, and **web-push notifications** that fire when a post is published.
- Service worker + VAPID keys; `SendNewPostNotification` job; `PushSender` service (prunes expired subscriptions).
- Admin: **Subscribers** resource + dashboard subscriber/push stats.
- DB: `subscribers`, `push_subscriptions`, `posts.push_notified_at`.
- Env fixes recorded for production: PHP `gmp` extension + CA bundle for outbound HTTPS; jobs use the queue (worker required).
- Verified: subscribe + push-subscribe endpoints store data; publish → queued job → full VAPID sign/encrypt succeeded.

## 2026-07-16 — Phase 2: Shop
- Products, session cart, checkout, orders + order items, and a sales dashboard.
- AJAX add-to-cart with nav badge; cart/checkout/confirmation pages; dynamic shop page + homepage shop section.
- Admin: **Products** (pricing/inventory/image/tag), **Orders** (status workflow, pending-count badge), **SalesOverview** dashboard widget.
- DB: `products`, `orders`, `order_items`. Payment: none yet — checkout places a "pending" order.
- Verified end-to-end: added items → checkout → order created and shown in admin + dashboard.

## 2026-07-15 — Phase 1: CMS + public site
- Posts, Categories, Media in the admin; the approved dark 3D design ported into Laravel Blade, rendering from the database.
- Home (DB-driven hero slider, trending strip, per-category layouts), single-post pages (view counting, related), category archives, and About/Contact/Privacy/Terms pages.
- Roles/permissions (spatie); seeded 5 categories + sample posts.
- Verified: full site renders from the DB; admin post/category CRUD works.

## 2026-07-14/15 — Phase 0: Foundation
- Installed a portable PHP 8.3 + Composer toolchain on the dev machine (no admin rights); scaffolded **Laravel 13 + Filament v3** + spatie/laravel-permission.
- Created the admin panel and first admin user; verified the login renders.

## 2026-07-14 — Static design (pre-backend)
- Built the original front-end mockup (dark, 3D, brand-red) approved by the user: breaking ticker, glass nav, ultra-modern auto-playing hero **post slider**, trending strip, distinct per-category layouts, patriot shop grid, animated canvas background. These static files (`index.html`, `css/`, `js/`) live at the project root as the design reference.

---

## Decisions on record
- **Custom build** (not WordPress) on **Hetzner**, per the user's choice.
- **AI provider: OpenAI** (switched from Anthropic).
- **Legal-safe ingest**: rewrite with attribution, drafts by default, no source-image copying by default.
- **Categories**: Politics, US News, World, Story of Hope, Opinion (+ Patriot Shop).
- **Deferred**: online payment provider for the shop.
