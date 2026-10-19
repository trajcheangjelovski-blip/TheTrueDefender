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

**Permanent architecture (2026-10-19):**
`BRAVE SEARCH → GPT-5.4 RESEARCH → GPT-5.4-mini WRITING → GPT-5.4 FACT-CHECK → FINAL FRESHNESS CHECK → PUBLISH`.
Nothing publishes straight from ingest; every news story is researched before it is written.

**Models** (`App\Support\AiModels`; admin settings win, else env):

| Job | Setting | Env | Default |
|---|---|---|---|
| Research analysis | `research_model`, `research_reasoning_effort` | `PULSE_AI_RESEARCH_MODEL`, `PULSE_AI_RESEARCH_REASONING` | `gpt-5.4`, `medium` |
| Writing (+ utility calls) | `openai_model` | `PULSE_AI_MODEL` | `gpt-5.4-mini` |
| Fact-check | `fact_check_model`, `fact_check_reasoning_effort` | `PULSE_AI_FACT_CHECK_MODEL`, `PULSE_AI_FACT_CHECK_REASONING` | `gpt-5.4`, `medium` |

All three use Chat Completions with strict JSON-schema output; `reasoning_effort` is sent only
for research/fact-check (verified working with gpt-5.4 on 2026-10-19). Research and fact-check
never fall back to the writing model: an unavailable model (checked against `/v1/models`) holds
the story with the error recorded.

| Stage | What happens | Where |
|---|---|---|
| Discovery | Feeds polled every minute; AI editorial score per section; duplicate stories merged; nothing is published here | `IngestService` |
| Sources | The story's own report + independent coverage via **Brave Search API**. Wire services and official sites ranked first, then established outlets. Blogs/platforms blocked; republished copies dropped. Each source recorded with publisher, headline, publish/updated dates, access time | `WebResearcher`, `ArticleFetcher` |
| Cost gate | GPT-5.4 research only for stories that can win a slot today (per section: open slots + 1, best-ranked first); breaking always; outside the publishing window only breaking | `posts:synthesize` |
| **Research (gpt-5.4)** | Structured package: the ONE event (what/who/where/type), event date + basis, each source's publish/update date, latest development + date, newsworthiness (`new_event`, `new_development`, `background_explainer`, `republished_old_event`, `not_news`), breaking eligibility, confirmed facts tied to packets, quotes, chronology, dated background, conflicts, unverified claims, missing evidence, angle. Stored in `posts.research_package`; identity in `posts.event_key` / `event_date`. Reused for 2h (45 min if developing) when the sources are unchanged | `ResearchAnalyst::analyze` |
| Validation (code) | Sources about a **different incident** are dropped; republished old events / not-news **rejected**; unknown event date, needs-more-research or a latest development older than 2 days **held**; unsupported "breaking" label **removed**; the same event already covered with no newer development **rejected** (event identity, not headline similarity); too few independent sources ⇒ not written | `ResearchAnalyst::assess`, `EditorialPipeline::processDraft` |
| Writing (gpt-5.4-mini) | Writes from the verified package + evidence packets only; leads with the latest development; dates all background | `Rewriter::writeArticle` |
| Fact-check (gpt-5.4) | Independent check of every claim against the source texts **and** the package's event identity — details from another incident, or old developments presented as new, are critical. Verdict decided **in code**; a failed call is never a pass | `FactChecker` |
| Final check | Right before publishing (promotion and breaking lane): research present, event/date/freshness valid, breaking label supported, and for **developing** stories a live Brave check (`freshness=pd`, no cache) for coverage newer than the research — if found, the story goes back through research (max 2) | `App\Support\FinalCheck` |
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
| `research_model` / `fact_check_model` | `gpt-5.4` | Research and independent fact-check models (see §2); `openai_model` = writer |
| `research_reasoning_effort` / `fact_check_reasoning_effort` | `medium` | `none`/`low`/`medium`/`high` |
| `research_require_event_date`, `research_require_source_identity`, `research_check_latest_development`, `research_verify_event_identity`, `block_misleading_breaking`, `research_recheck_developing`, `research_top_candidates_only` | on | Research & publication safeguards (Admin → AI models & research) |
| `max_development_age_days` | 2 | A "new" story's latest verified development must be this recent |
| `ai_price_input_per_mtok` / `ai_price_output_per_mtok` | — | Only for models NOT in `AiModels::PRICES` |

## 5. AI usage and cost

Every pipeline call records tokens and cost **per stage and per model** (`posts.ai_usage`:
`by_stage`, `by_model` with reasoning tokens, `estimated_usd`, `brave_requests`), priced
per model from `App\Support\AiModels::PRICES` (gpt-5.4 $2.50/$0.25 cached/$15 per 1M;
gpt-5.4-mini $0.75/$0.075/$4.50; checked 2026-10-19). Shown in the article editor under
"Research & AI cost".

Measured before the research upgrade (all `gpt-5.4-mini`):

- New story: 2–4 calls, ~10k–33k tokens (≈ $0.03–0.06).
- Revision of a published article: 3–7 calls, ~22k–66k tokens.

Estimated with the 2026-10-19 architecture: ≈ $0.20–0.40 per published article (research
≈ $0.10, writing ≈ $0.02, fact-check $0.06–0.18); research runs only for slot candidates.

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

## 10. Evidence packets, needs-reporting and capped experiments (policy 2026.10.18-11)

- `SourcePacket` turns each source into a packet (publisher, exact URL, kind primary/news/background/citation-only,
  published/updated time, relevance-selected passages, key facts). It is applied once in `gatherSources` /
  `recordedEvidence`, so the writer, fix passes and fact-checker read identical excerpts. Source text is
  untrusted: instruction-like lines are stripped and the prompt frames packets as data.
- `SourcePacket::assess` and the writer's `status: needs_reporting` stop a confident draft when evidence is thin
  (`processDraft` returns `needs reporting: …`; before any paid call when the sources are too few).
- Writer requests carry `resources/editorial/examples.md` (two checked examples) in new and revision modes.
- `DraftCheck` (free): dates no packet states; article with no inline links.
- `AiClient` accepts a hard USD ceiling and refuses a call whose worst case would exceed it.
- `posts:preview-writing <post> --packets=file.json [--opinion] --ledger=name` rewrites a published article through the
  real writer as of its publication time, never saves it, and enforces a shared call/USD ledger across runs.
- Experiment record: `editorial/runs/2026-10-18-writer-v9/` (inputs, packets, outputs, drafts, ledger);
  prior prompt and code: `editorial/versions/`; lessons: `editorial/LESSONS.md`.
