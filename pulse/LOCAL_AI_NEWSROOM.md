# Zero-paid-model newsroom: safe implementation and rollout

## What this branch adds

- Ollama OpenAI-compatible transport for **structured newsroom** research, writing, fact checking, and revisions through `AiClient`.
- Reuse of existing RSS ingestion, deduplication, source extraction, editorial policies, fact checking, PostgreSQL and revision history.
- `FeedRelatedResearcher` discovers additional reports already in the site's RSS ingest database. A headline match is **only a candidate**, never verification.
- `posts:refresh-related` fetches fresh reporting and stores a reviewed **PostRevision**, never modifies the live article without editor approval.
- Optional Docker Compose overlay for a private CPU-only Ollama instance.

## Scope and limitations (important)

**Not a complete OpenAI replacement yet.** Legacy direct OpenAI calls still exist in `Rewriter::rewrite/score/mergeSource`, SEO, images, and several auxiliary jobs. Avoid assuming the entire unattended ingest pipeline is operational without paid keys. These must be moved to a common adapter and tested before production.

No AI or source count alone makes a story accurate. Political claims, event identities, quotations, names, dates, numbers and legal statements need substantive verification; the local model can fail even when it returns valid JSON. Use human review by default. AdSense approval is not guaranteed.

No unrestricted general web search is provided without a search service. Free feed research examines **sources already indexed** by the site's RSS ingestion. A configured web-search provider can supplement it (and may charge). Feed coverage varies.

This is **not yet scheduled** and changes **no default production settings**. Nothing auto-publishes because of this feature.

## Deployment test on nonproduction clone

1. Backup database and media, create a staging environment, measure `free -h` and `docker stats`.
2. From `pulse`, run `docker compose -f docker-compose.yml -f docker-compose.ollama.yml up -d ollama`.
3. Pull the small model: `docker compose -f docker-compose.yml -f docker-compose.ollama.yml exec ollama ollama pull qwen3:1.7b`.
4. Set in staging `.env`: `OLLAMA_ENABLED=true`, `OLLAMA_BASE_URL=http://ollama:11434/v1`, `OLLAMA_MODEL=qwen3:1.7b`. Leave this feature disabled on production pending testing.
5. Clear config cache and restart PHP workers after the stage is ready. Run `php artisan posts:refresh-related --limit=1 --dry` to preview; then `php artisan posts:refresh-related --limit=1` in staging.
6. Check the revision status, trace URLs and dates back to sources, verify body originality, model timing, memory pressure, and no unintended live edits. A pending review is not a verified publication.
7. Run `php artisan test --filter=FeedRelatedResearcherTest`. Existing suites should also pass before merge.

## Resource limits

On 4 vCPU / 8 GB RAM shared with PostgreSQL, PHP workers and Redis, CPU inference may be slow or cause memory pressure. Overlay caps Ollama at 4 GB memory and 2 CPU cores, but it does not guarantee availability; a 4GB cgroup can cause Ollama to OOM. Do not run parallel research, writer and checker jobs. Reserve memory for database and web.

## Release checklist

- [ ] No paid endpoint used by scheduled jobs in local-only mode
- [ ] Unit/feature tests for the entire ingest → source validation → draft → review path
- [ ] Human-evaluated 20-article sample with zero fabricated event facts or invented quotes
- [ ] Assert separate independent outlets rather than syndicated copies
- [ ] Image licensing and relevance validation (RSS/og:image alone is not a usage license)
- [ ] Explicit original publication date and material-update date; do not mislabel old news breaking
- [ ] Rate limits, SSRF protections, per-host crawling policy, safe image fallbacks
- [ ] Editorial transparency, real contributor/byline accuracy, corrections, privacy and AdSense compliance review
- [ ] Production backup, staged rollout, alerting, and rollback plan

## Current model workflow

RSS sources → existing ingested-item database → heuristic candidate match → original articles and additional evidence → existing research/writer/fact-check logic on local Ollama → PostRevision pending editor approval → approved update to original URL.

No duplicate article is created for an update. A lack of trustworthy material new evidence leaves the original text unchanged.
