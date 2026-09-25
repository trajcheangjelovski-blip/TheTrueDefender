# AdSense "Low Value Content" — Audit & Remediation

**Date:** 2026-09-25
**Site:** https://thetruedefender.news (Laravel app in `pulse/`)
**Scope:** live-site audit + codebase fixes. The 357→**~2,267** published articles live in the
production PostgreSQL DB, which is **not** in this repo and was **not** reachable from this
environment (no PHP/DB/deploy access here). So data-level changes are delivered as **reversible
artisan commands** for you to run on the server; everything else is code you can deploy.

> Honest framing up front: the site is **technically healthy**. The AdSense rejection is a
> **content-quality/scale** judgment, and no code change alone will clear it. The single biggest
> factor is that the site publishes a very large volume (~2,267) of AI rewrites of other outlets'
> reporting — which is exactly what Google's "scaled content" / "low value" policy targets. The
> code fixes below remove concrete defects and reduce shop/interruption noise; the durable fix is
> editorial (fewer, more distinct, genuinely additive articles). See the checklist at the end.

---

## What I verified on the live site

**Healthy (no action needed):**
- `robots.txt` → 200, correct `Disallow` set, both sitemaps referenced.
- `sitemap.xml` → 200, `application/xml`, **2,986 URLs**; `news-sitemap.xml` → 200.
- Article pages have a **self-referential canonical**, `max-image-preview:large` (no `noindex`),
  and **server-rendered body text** (fully crawlable).
- **Byline present on 100%** of sampled articles (TheTrueDefender — Editorial Team + date + reading time).
- **Thin content ~0%** in a 120-article spread sample (the 400-word publish floor is working).

**Problems found (sampled 120 articles spread across the catalog):**
| Signal | Prevalence in sample | Est. catalog-wide |
|---|---|---|
| AI tell — "the new report says/adds", "according to the report" | ~9% | ~200 articles |
| Implied first-hand outreach — "did not respond to a request for comment" etc. | ~5% | ~110 articles |
| Literal placeholder token (e.g. `[[LINK]]`) | rare (confirmed on the IRS story) | small but real |
| Duplicate/overlapping coverage of the same event | confirmed (the two IRS-ICE stories) | unknown — needs the review command |

---

## 1) Changes made (files + URLs)

### Prevents the `[[LINK]]` class of defect (going forward)
- **`pulse/app/Support/ArticleSanitizer.php`** — new `stripPlaceholders()` (+ `hasPlaceholder()`),
  now run inside `clean()` and `cleanText()`. Strips leftover template tokens (`[[…]]`, `{{…}}`,
  `[LINK]`, `[SOURCE]`, `[citation needed]`, `[insert …]`, `[TBD]`, …) while deliberately leaving
  legitimate editorial brackets (`[sic]`, `[1]`, party labels) untouched. Because every AI body,
  excerpt, caption, takeaway and FAQ already passes through this class, the literal `[[LINK]]` can
  no longer reach readers.
- **`pulse/app/Services/Rewriter.php`** — added two hard rules to the main rewrite prompt (and the
  merge + expand prompts): **never emit placeholder/template tokens**, and **never claim the outlet
  contacted anyone / that a source "did not respond to a request for comment"** unless the outreach
  is explicitly attributed to the original outlet. This matches your editorial standard (established
  reporting + automated drafting; no invented interviews/outreach).

### Retired-URL handling (301 redirects) — new, reversible
- **`pulse/database/migrations/2026_09_25_120000_create_post_redirects_table.php`** — `post_redirects` table.
- **`pulse/app/Models/PostRedirect.php`** — resolver with redirect-chain/loop protection.
- **`pulse/app/Http/Controllers/PostController.php`** — a retired (unpublished) article now **301s**
  to its replacement instead of 404-ing.
- **`pulse/routes/web.php`** — the `/post/{post:slug}` route's `missing()` handler also 301s when the
  row is gone entirely. Clean URLs and search authority carry over.

### UX — journalism first, less shop/interruption in reading paths
- **`pulse/resources/views/partials/nav.blade.php`** — the persistent "Free Gifts" nav item is no
  longer a gold, emoji'd CTA; it's a normal nav link (shop still reachable). This is the reading-path
  de-emphasis, since the nav shows on every article.
- **`pulse/resources/views/home.blade.php`** + **`pulse/app/Http/Controllers/HomeController.php`** —
  the homepage shop block moved **below** the newsletter and trimmed from 8 products to **one row (4)**,
  with softer copy. The homepage now leads with journalism → newsletter, shop last.
- **`pulse/public/js/audience.js`** — the auto email **modal no longer pops open over articles**
  (it fired at ~45% scroll / 25s dwell on every page, including mid-read on mobile — the worst
  interruption). Articles already carry an inline capture mid-story and one at the end; the header
  **Subscribe** button still opens the modal on demand everywhere. The push-bar and cookie banner
  are unchanged (bottom bars, snoozeable).

### New tools you run on the server (see §3 for commands)
- **`pulse/app/Console/Commands/ContentScrubPlaceholders.php`** — `content:scrub-placeholders`
  (dry by default; `--apply` writes a JSON backup first; `--restore=FILE` reverts).
- **`pulse/app/Console/Commands/PostsMerge.php`** — `posts:merge` consolidates a duplicate and
  creates the 301 (retired row is only unpublished, never deleted; `--undo` reverses it).
- **`pulse/app/Console/Commands/PostsReviewFlags.php`** — `posts:review-flags`, **read-only**;
  writes the editorial review list (CSV + JSON) described in §2.

---

## 2) Articles requiring your editorial review

I did **not** rewrite article text or invent replacements. Run `php artisan posts:review-flags`
(read-only) on the server to generate the full list; it flags each article with a reason:

- **The two IRS-ICE stories (confirmed duplicate).** Both cover the *same* D.C. Circuit ruling on
  the *same* date (Sep 9, 2026):
  - `/post/court-upholds-block-on-irs-data-sharing-with-ice` — more substantive, better-attributed,
    fuller legal detail. **Recommended survivor**, after fixing its body (see below).
  - `/post/obama-appointed-judges-block-irs-ice-data-sharing` — thinner, more partisan reframe;
    repeats the AI tell "the new report says" ~6×. **Recommended to retire → 301 to the survivor.**
  - The survivor still needs two content fixes only you should approve: it contains a literal
    `[[LINK]]` (auto-fixable via `content:scrub-placeholders`) and the malformed phrase
    *"the fight over ICE data sharing **with ICE**"* (duplicate — needs a manual one-word edit in
    admin), plus the line *"did not respond to requests for comment by publication time"* which
    implies first-hand outreach and should be reworded or attributed.
- **~200 articles with AI tells** ("the new report says/adds", "according to the report"). These read
  as visible single-source rewrites. Prioritize re-editing the highest-traffic ones.
- **~110 articles implying first-hand outreach** ("did not respond to a request for comment", "we
  reached out"). Reword to attribute to the original outlet, or remove.
- **Any other duplicate clusters** the command surfaces (near-identical headlines within a few days).

For any of these you can't substantively improve from verifiable sources, the honest options are to
leave them attributed and reworded, or unpublish/merge them — not to pad them with AI filler.

---

## 3) Changes that need database / deployment access I did not have

Run these on the server (`/opt/ttd`) after `git pull`. **Migrate first**, then the data commands.
Per your deploy note: never restart `app` while a background ingest/expand job is running.

```bash
# 1. Create the redirects table
php artisan migrate

# 2. See what placeholder scrubbing WOULD change (dry run, changes nothing)
php artisan content:scrub-placeholders

# 3. Apply it — writes a JSON backup to storage/app/private/backups/ first
php artisan content:scrub-placeholders --apply
#    revert if needed:  php artisan content:scrub-placeholders --restore=backups/scrub-placeholders-YYYYMMDD-HHMMSS.json

# 4. Consolidate the two IRS-ICE articles (retire #2 → 301 to #1). Reversible.
php artisan posts:merge obama-appointed-judges-block-irs-ice-data-sharing court-upholds-block-on-irs-data-sharing-with-ice --reason="duplicate IRS-ICE ruling, Sep 9"
#    undo:  php artisan posts:merge obama-appointed-judges-block-irs-ice-data-sharing court-upholds-block-on-irs-data-sharing-with-ice --undo

# 5. Generate the editorial review list (read-only) → storage/app/private/reports/
php artisan posts:review-flags

# 6. Re-deploy code (Blade/PHP/JS): git pull + docker compose restart app  (per DEPLOY.md)
```

Notes:
- The survivor's `[[LINK]]` is fixed by step 3; the "with ICE" duplication is a one-word manual edit
  in the admin post editor (I can't reach the DB text from here).
- `posts:merge` sets the retired post's status to `archived` (kept for `--undo`; deliberately not
  `draft`, so the stale-draft sweeper can't delete it).

---

## 4) Honest checklist before requesting another AdSense review

- [ ] Deploy this code and run the commands in §3 (migrate, scrub, merge, review list).
- [ ] Work the `posts:review-flags` output: re-edit or retire the AI-tell (~200) and outreach (~110)
      articles, starting with the most-trafficked. This is the real content-quality lever.
- [ ] **Reduce publishing volume / raise the bar.** ~2,267 AI rewrites is the core "scaled content"
      risk. Consider pausing auto-publish and shifting to fewer, more distinct, genuinely additive
      stories; deactivate feeds that only reproduce higher-authority outlets.
- [ ] Confirm the survivor IRS article reads cleanly (no `[[LINK]]`, no "with ICE", outreach line reworded).
- [ ] Spot-check on a real phone that articles read without a modal interrupting mid-scroll.
- [ ] Verify source links point to the specific underlying report/primary document where possible
      (the review command lists articles with no source recorded).
- [ ] Only after the catalog has matured, request the AdSense review yourself.

**I make no claim that these changes guarantee AdSense approval, and I have not submitted the site
for review.** Approval is Google's editorial judgment; the volume-of-AI-rewrites factor is the one
most likely to keep it flagged and is an editorial decision only you can make.
