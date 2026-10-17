# Editorial System — how TheTrueDefender writes, checks and publishes

Every AI-written or AI-rewritten article goes through one pipeline under one
version-controlled editorial policy. This document is the map.

## 1. The editorial policy (permanent, versioned)

- **Rules:** `resources/editorial/policy.md` — accuracy, attribution, which sources count,
  writing standards, banned filler patterns, structure by story type, news vs. opinion,
  headlines/SEO, takeaways, revisions and corrections.
- **Version:** the `version:` line at the top of that file (currently `2026.10.18-5`).
  **When you change the rules, bump the version.**
- **Loaded by:** `App\Support\EditorialPolicy::forPrompt()` in every writing path
  (new stories, revisions, fix passes, freshness merges, ingest drafts, opinion columns).
  The admin "house style" setting is appended as subordinate *voice notes* — it can shape
  tone but never overrides the policy. The weekly "learned style guide" is also subordinate.
- **Recorded:** `posts.policy_version` and `post_revisions.policy_version`.
- **Enforced in code, not only in prompts:** `App\Support\PublishGate` and
  `App\Services\FactChecker` (below).

## 2. The pipeline (`App\Services\EditorialPipeline`)

| Stage | What happens | Where |
|---|---|---|
| Discovery | Feeds polled every 2 min; AI editorial score; duplicate stories merged | `IngestService` |
| Research | The story's own report + independent coverage via **Brave Search API**. Wire services and official sites ranked first, then established outlets. Blogs/platforms blocked; republished copies (scraper sites) dropped. Each source recorded with publisher, headline, publish/updated dates, access time | `WebResearcher`, `ArticleFetcher` |
| Validation | Too few independent sources ⇒ the story is not written | `EditorialPipeline::processDraft` |
| Writing | One writer under the policy: new stories, revisions, fix passes | `Rewriter::writeArticle` |
| Fact-check | **Separate model call** checks every claim against the source texts only. Verdict decided **in code**: any critical problem blocks; no evidence = never ready | `FactChecker` |
| Revision | Up to **2 fix passes** on findings or style failures, then a re-check | `EditorialPipeline::checkAndFix` |
| Quality | Headline echo, repeated/reused sentences, stock headings, banned filler and vague attribution (quotes excepted), rhetorical questions, opinion inside news, length by story type | `PublishGate` |
| Publication | **New stories:** auto-publish only with fact-check verdict `ready` (+ cap, pacing, section reserves). **Revisions of published articles:** saved as drafts; an editor must approve | `posts:promote-queued`, `BreakingPublisher`, Admin → Revisions |

Fact-check verdicts: `ready` · `needs_rewrite` (fixable errors) · `needs_research`
(the sources don't establish a claim) · `rejected`.

Edits to **live** articles (the 15-minute freshness job and ingest duplicate merges) are
saved only if the edited text passes the fact-check and originality rules
(`EditorialPipeline::acceptUpdate`). Later developments go in a dated "Update" note.

## 3. Revising published articles

```bash
php artisan posts:revise-live --dry          # what would be revised
php artisan posts:revise-live --limit=10     # next 10 not yet revised under this policy
php artisan posts:revise-live --ids=1,2,3    # specific articles
php artisan posts:revise-live                # everything remaining
```

- **Safe to re-run / resumable:** articles that already have a revision under the current
  policy version are skipped; a per-article lock prevents duplicate work.
- **Backups:** every run writes `storage/app/backups/revise-live-<timestamp>.json` first, and
  each revision stores a full `snapshot` of the article.
- **Historical accuracy:** the writer works as of the original publication moment. Sources
  published or updated *after* the article are labeled; later developments go only into a
  separate field that code turns into a dated "Update (Month Day, Year):" paragraph.
- **Never live without approval.** Approve in **Admin → Content → Revisions**, or:

```bash
php artisan posts:revision list --status=pending_review
php artisan posts:revision show 12
php artisan posts:revision apply 12 --editor=you@example.com [--confirm-correction=1]
php artisan posts:revision rollback 12
```

- **Applying keeps** the article ID, URL slug, original publication date, image, author and
  category; `updated_at` (schema `dateModified`) moves.
- **Corrections need a human.** The AI *proposes* corrections (only critical errors in the
  published version that the sources settle); a correction note is published only for the
  ones the editor ticks. Bulk approval never publishes corrections.
- **Rollback** restores the stored snapshot exactly.

## 4. Settings (Admin → AI Settings, or the `settings` table)

| Setting | Default | Meaning |
|---|---|---|
| `daily_publish_cap` | 10 | Posts per New York day |
| `breaking_reserve_slots` | 2 | Cap slots kept for breaking news |
| `category_reserves` | `{"world":1,"story-of-hope":1,"opinion":1}` | Section slots; released at `category_reserve_release` (20:00) |
| `min_sources_to_publish` | 3 | Independent sources for a news story |
| `breaking_min_sources` | 2 | Sources for the breaking fast lane |
| `min_publish_words` | 600 | Length floor (lower for breaking 350 / crime & human-interest 400) |
| `fact_check_model` | (same as `openai_model`) | A stronger model here improves fact-check consistency |
| `ai_price_input_per_mtok` / `ai_price_output_per_mtok` | — | USD per 1M tokens; turns recorded token usage into a cost estimate |

## 5. AI usage and cost

Every pipeline call records tokens (`posts.ai_usage`, `post_revisions.ai_usage`, by stage).
Measured on the first runs with `gpt-5.4-mini`:

- New story: 2–4 calls, ~10k–33k tokens.
- Revision of a published article: 3–7 calls, ~22k–66k tokens.

Cost savers: page and search results cached for 6 hours (research, writing and
fact-checking share one download); the AI write is skipped entirely when there aren't
enough sources; the SEO step writes metadata only (it no longer rewrites bodies);
fix passes are capped at two.

## 6. Tests

`tests/Feature/EditorialSystemTest.php` — policy loading/versioning, every PublishGate rule,
fact-check verdict logic, source-copy and blog detection, the corrections filter and editor
confirmation, revision apply/rollback, later-source labelling, and the admin Revisions
screen. All network traffic is blocked in tests.

Production has no dev dependencies, so tests run in a throwaway copy:

```bash
git clone --depth 1 https://github.com/trajcheangjelovski-blip/TheTrueDefender.git /tmp/ttd-test
docker run --rm --entrypoint sh -v /tmp/ttd-test/pulse:/var/www/html -w /var/www/html dailypulse-app \
  -c "cp .env.example .env && composer install -q && php artisan key:generate -q && php artisan test --filter=EditorialSystemTest"
```

## 7. Known limitations

- The fact-checker only judges against the source excerpts it is given (first 5,500
  characters of the main source, 3,500 of others). With `gpt-5.4-mini` it is sometimes
  inconsistent; set `fact_check_model` to a stronger model for better reliability.
- Brave Search returns current coverage; for old articles, earlier reporting may no longer be
  ranked, so historical verification is limited to what is still online.
- Source ranking prefers known outlets; unknown sites are still allowed and should be
  reviewed by an editor.

## 8. Public source lists (2026.10.17-3)

Public articles never include a standalone Sources, References or Bibliography section.
The article template no longer renders `posts.sources`; these records and the independent
fact-check remain internal. Use necessary in-text attribution and occasional exact research
links. End on a meaningful verified detail, without a mandatory conclusion or disclaimer.

`PublicSourceSections` detects HTML, Markdown and plain-text section labels. `PublishGate`
feeds violations into the writer's existing fix loop and blocks publication if they remain.
`Post::save` also guards manual publication, scheduled publication and `saveQuietly` updates.
Ambiguous content is held for editorial review, never deleted by a broad regular expression.
Link enrichment cannot change the checked wording, append source lists or remove existing links.

Audit without changing articles:

```bash
php artisan posts:audit-source-sections
php artisan posts:audit-source-sections --all --snapshot
```

Snapshots include complete original article fields and research records, stored only under
`storage/app/private/editorial-audits/`. The audit is idempotent and returns a nonzero exit
code for embedded source-section candidates that need review.

The initial production audit found 19 published articles, all with separate internal research
records, and zero embedded source sections. Therefore no article-body migration was needed.
Rollback is a code rollback; there are no changed article bodies or research records to restore.
Keep the pre-deployment private snapshot to compare bodies, metadata and source evidence.

Regression coverage: `tests/Feature/PublicSourceSectionsTest.php` exercises detection,
publication and quiet-update guards, rendered pages and canonical metadata, private snapshots,
inline links, all writer modes and the actual scheduled synthesis command with mocked external
research/AI responses. Existing fact-check tests remain in `EditorialSystemTest.php`.

## 9. Inexpensive private writing trials

```bash
php artisan posts:preview-writing 5606 --check --feedback="Keep attribution clear without repeating outlet names. Use a short quotation only if the exact words matter. End on the central story."
```

This command never writes to the articles table or publishes anything. It reuses up to three
recorded sources through the existing page cache, writes a fresh draft from that research,
makes one writing call and optionally one
independent fact-check call, and saves the sample, evidence, findings and token usage in private
`storage/app/private/editorial-previews/`. It skips search, SEO, images and automatic fix passes.
Each call is limited to 3,000 output tokens and 100,000 input bytes including schema; failed
requests are not retried. Dollar cost depends on the configured models and current API prices.

Review one sample at a time. Change a specific rule only when the text shows a real problem,
compare the next sample on the same research, and keep accuracy and publication checks intact.
Policy v4 adds focused leads, readable paragraph structure, selective quotations, less repetitive
attribution and story-specific endings. The fact-check prompt checks existing attribution before
requesting more and distinguishes genuine contradictions from different times or geographic scope.
Any unresolved claim in the checker's `unverified` list forces `needs_research`; the publication
gate also checks that list independently, even when an older record has a stale `ready` label.

Policy v8 adds checks for named-person and political-race mix-ups, unsupported exact quotations,
repetitive retelling of the same account, and investigative stories that blur allegations with
findings. It also prevents stitching separate quote fragments together. `ExactQuoteVerifier`
independently compares direct quotations in a draft with the recorded source excerpts and sends
mismatches back to the existing automatic correction and publication checks. The preview command
reports the full publication-gate result and checks rewrites using their original publication date.
`NamedEntityVerifier` compares role-linked person names with the source excerpts, preventing the
model from expanding a partial surname into an unsupported full name.
