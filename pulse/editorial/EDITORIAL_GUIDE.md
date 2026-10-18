# Editorial guide (short) — writer policy v2026.10.18-11

The binding text is `resources/editorial/policy.md` (sent with every writer request) and
`resources/editorial/examples.md` (two checked before/after examples, sent with new-story and
revision requests; hand-written by an editor, not model output). Older versions: `editorial/versions/`.

Rules in one page:
1. Lead with the most important verified development; the headline's promise is delivered in the first paragraph.
2. Structure and length follow the story. No fixed headings, takeaways or "Our Take". No generic closing lesson.
3. Straight news is factual; opinion is labeled and states factual premises that packets support.
4. Never invent quotes, interviews, eyewitnesses, motives, numbers, credentials, or our own reporting.
5. Every material claim traces to an evidence packet; link the exact packet URL beside the claim. Credit a fact to the packet that holds it.
6. Another outlet's reporting is attributed to that outlet. Wikipedia = background only.
7. Dates, names, numbers, timelines: use only what packets state; time-sensitive facts are "as of" with date and time zone.
8. Forecast probability is not observation; say who issued it and when.
9. Add verified value (document, exact figure with date, process explanation), not a reworded article.
10. Uncertain or conflicting evidence goes to `reporting_gaps`; insufficient evidence returns `needs_reporting` and no draft.

Code that enforces this (not prompt-only): `SourcePacket` (packets, untrusted-text frame, sufficiency check),
`ExactQuoteVerifier`, `NamedEntityVerifier`, `DraftCheck` (dates/links), `PublishGate`, `FactChecker`,
`AiClient` cost/call/input caps.

Reuse: fetched pages are cached 6 hours (ArticleFetcher); `SourcePacket::prepare` shrinks a source once, so the
writer, fix passes and fact-checker see identical excerpts.
