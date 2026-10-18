# Lessons from the writer-v9 experiment (2026-10-18)
Reusable rules; add to policy only when a lesson repeats. Evidence: editorial/runs/2026-10-18-writer-v9/.

| # | Observed failure | Rule / control | Status |
|---|---|---|---|
| 1 | Opinion argued for orders already lifted; no source showed pressure | Opinion premises must be in packets; else `needs_reporting` (policy §11, Example B) | Worked (run 1) |
| 2 | Fact credited to the wrong outlet (ABA run 1) | "Credit each fact to the packet that holds it" (v10) | Fixed in run 2 |
| 3 | Stale "upcoming hearing" remark kept as present tense | "Order statements by date" (v10) | Partly fixed in run 2 (Hart quote still undated) |
| 4 | Date invented from a publication date ("Sept. 25") | Policy v11 + `DraftCheck::unsupportedDates` (free, deterministic) | Caught by code, not yet re-tested on writer |
| 5 | Drafts with zero inline links (runs 2 and 4) despite the rule | `DraftCheck::missingLinks`; prompt rule alone is unreliable → needs a code step (see Next) | OPEN |
| 6 | Two forecasts from different dates placed side by side (El Nino: Sept figures from the governor's release next to NOAA Oct. 8) | Policy: state forecasts with issuer and date | OPEN: no calls left to test |
| 7 | CPC page had no extractable issue date; study page 403 | `SourcePacket` reads a date from page text when metadata is missing; `citation` entries for editor-verified unreachable documents | Partly: CPC date still not found |
| 8 | Writer ignored the citation-only packet for the actual study | none yet | OPEN |
| 9 | 60 KB input guard silently refused a legitimate request | Guard raised to 90 KB; refusals now logged | Fixed |

Next candidates (not done): a post-write step that links attributed outlet names to their packet URLs (LinkEnricher already
does part of this in production); a date-label requirement for every forecast figure in the output schema; one fact-check
call per draft (not run here because of the 4-request limit).
