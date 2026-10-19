# Political news upgrade: Alberta referendum regression run (2026-10-19)

Status: code and tests are committed locally. NOTHING IS DEPLOYED. The live article (post 5696) is unchanged.

## What was added (all additive; disabled sections and model roles unchanged)
- Research package: polls (organization, fieldwork, population, measure, question, undecided, limits), legal layers, petitions (submitted vs verified), appearances (official record vs news), event lifecycle, reconciled discrepancies.
- Official-source queries: Elections Alberta, Government of Alberta, PM site, Abacus, Angus Reid, Leger, courts. Journalism is kept.
- Deterministic checks (no model calls): `PoliticalCheck` (poll population/measure, non-binding implied as binding, submitted signatures called verified, needless "reports differ", wrong weekday vs official record), `EventLifecycle`, ending-repeats-opening, excerpt/lead and excerpt/headline overlap, near-duplicate sentences, exact-quote punctuation.
- `posts:flag-outdated-previews` (hourly, no AI) creates a `needs_research` revision for preview articles whose event has passed; `FinalCheck` blocks auto-publishing them.
- Policy 2026.10.19-4; `AlbertaReferendumTest` (9 tests, covers the 12 requested checks). Full suite: 172 passed (980 assertions), run on the server in a throwaway copy.

## Alberta re-research (draft revisions only, nothing overwritten)
Revisions #55-#58 were created. None passed every gate, so none is `pending_review`; all are `needs_rewrite` and nothing was published.
- Best (#58): correct ballot wording, Edmonton speech dated Friday per the PM transcript (no false "reports differ"), poll populations and measures correct (74% decided voters; 61% and 62% are expectations), petition submitted (about 300,000, BBC) vs verified (nearly 223,000 of almost 280,000, Elections Alberta via Global News) kept apart, non-binding status and legal path explained, court ruling and appeal stated, excerpt and ending no longer repeat the lead.
- Remaining problems in #58: the lead says ballots "are being counted" although the hand count starts no earlier than 8 p.m.; one quote lost its leading "And"; one quote lacks attribution. These are fixable in one more pass.
- Findings on the live article: "reports differ Thursday or Friday" about Carney is unnecessary (the official transcript says Friday, Oct. 16, Edmonton); the 74% figure is decided voters only; the Angus Reid 62% is an expectation, not support; the ending repeats the opening.

## Cost (corrected)
Earlier reports understated cost because the client used mini prices for gpt-5.4 calls. Recorded costs from `ai_usage.by_model`:
- Quality upgrade revisions #49-52: about $0.546 (reported before as $0.244).
- Developing-news #53-54: about $0.71, plus one uncounted cut-off research call of about $0.07 (reported before as about $0.36).
- Alberta #55 $0.221, #56 about $0.555, #57 $0.645, #58 $0.422 (about $1.84 in total, plus one unrecorded cut-off research call). Pricing is now fixed in `AiClient`.

## Open
Deploy only on your instruction. One more revision pass (reusing the saved research package, about $0.4) should clear #58's remaining critical.
