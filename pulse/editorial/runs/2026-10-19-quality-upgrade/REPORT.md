# Quality upgrade report (2026-10-19)

Built on top of the pipeline already on `main` (research stage on GPT-5.4, writer GPT-5.4-mini, fact-check GPT-5.4, final check,
ArticleSections switches). Nothing was replaced; every item below is additive.

## Already present (reused, not rebuilt)
- Models: `AiModels` (research gpt-5.4, writer gpt-5.4-mini, fact-check gpt-5.4; fact-check never falls back to the writer). Production settings
  `fact_check_model` / `research_model` are empty, so these defaults apply. Unchanged.
- Event identity, dates and freshness: `ResearchAnalyst` (same_event per packet, event_date, latest development, newsworthiness) and `FinalCheck`.
- Section switches (Key Takeaways, Our Take, Sources) OFF in `ArticleSections`; Brave Search in `WebResearcher`; revision history; publish gate.

## Added or extended
| Requirement | Change | Files |
|---|---|---|
| Original source vs copies (Part 2) | `SourceRelations`: attribution ("told CBS Boston"), shared wording/quotations, aggregator hosts decide origin; packets get `relation`, `group`, `derived_from`; the evidence block tells the model a derived packet "does NOT independently confirm"; `assess()` and `PublishGate::distinctSourceCount` count independent groups, not websites | `Support/SourceRelations.php`, `Services/SourcePacket.php`, `Support/PublishGate.php` |
| Event date from original (Part 6) | `SourcePacket::datesMentioned` resolves stated and relative dates against each packet's publication date; shown as "dates mentioned" next to the publication time | `Services/SourcePacket.php` |
| Brave queries (Part 3) | `WebResearcher::focusedQueries`: the story's longest quotation, its main people/places, its headline number; up to `research_extra_queries` (default 2) extra Brave searches, merged. Headline search kept | `Services/WebResearcher.php`, `Services/EditorialPipeline.php` |
| Quotations (Part 4) | `ExactQuoteVerifier` now names the failure: remarks joined into one quotation (critical), plus a conservative attribution check (minor). It already blocked non-verbatim quotes; critical findings keep the verdict out of "ready" | `Support/ExactQuoteVerifier.php` |
| Numbers and units (Part 5) | `NumberUnitCheck`: quantities compared with units (gallons, barrels, liters, metric tons, tons, pounds, kg, miles, acres, %, dollars), vague magnitudes ("hundreds of thousands of gallons") compared by unit, packet-to-packet unit conflicts; never converts; rounding under 5% is minor | `Support/NumberUnitCheck.php`, `Services/FactChecker.php` |
| Statement chronology (Part 6) | Research quotes now carry `occasion` and `date`; the writer block prints them; the GPT-5.4 checker is told to flag an earlier remark presented as a later one | `Services/ResearchAnalyst.php`, `Services/FactChecker.php` |
| Openings and endings (Parts 7-8) | `LeadCheck`: excerpt repeating the lead (sentence similarity or 80% containment) and a background-only ending; both feed the existing fix loop through `PublishGate::originality`; fix-loop probes now carry the excerpt | `Support/LeadCheck.php`, `Support/PublishGate.php`, `Services/EditorialPipeline.php` |
| Rules | `policy.md` 2026.10.19-2: six additive bullets (units, statement chronology, original reporting and independence, quotation continuity, lead selection, ending). Nothing removed; "Teach the AI" house notes untouched | `resources/editorial/policy.md` |
| Fact-check prompt | One paragraph per topic added to the GPT-5.4 checker prompt; evidence block shows source relationships | `Services/FactChecker.php` |
| Revision tool | `posts:revise-from-packets` (saves revision drafts only) with `--allow-single-origin` for an editor override | `Console/Commands/PostsReviseFromPackets.php` |
| Test fixtures | `ResearchPipelineTest::text()` produced the same wording for every "source"; with copy detection that is correctly one report, so each call now yields distinct wording | `tests/Feature/ResearchPipelineTest.php` |

## Tests
152 passed (799 assertions), including `QualityUpgradeTest` (the ten requested scenarios: original source, event date, joined quotes, units,
statement chronology, repeated lead, background ending, sections OFF, publishing safety, model/Brave configuration). Local PHP tests ran in a throwaway
copy on the server; the lint and the suite are clean.

## The two articles (draft revisions only; live pages untouched)
Sources were chosen by hand (original reports first) because the pipeline's own Brave search is not run for revisions. The server could not fetch
some pages (HTTP 403/406): the first CBS Boston article (Logan Hall), Colorado Politics and Euronews were skipped.

### Urban Air (post 5693)
- The new checks flag the live article: it ends on how long the park has operated (background-ending) and its excerpt restates the lead.
- Revision **#49**: `pending_review`, fact-check ready, 0 critical. Credits CBS Boston, uses the names and quotations as CBS prints them (e.g. "Anna Held"),
  headline and lead built around the teenagers including Tristan, no park history, ends on the park's plan to invite everyone back. Needed
  `--allow-single-origin`: every other source is an aggregator of CBS Boston, i.e. one piece of reporting.
- Not resolved: CBS's other report (WBZ, Logan Hall) says the visit was "on Monday"; the server could not fetch it, so the draft says "earlier this week".

### Rubio (post 5701)
- Verified independently: Trump's Oct. 9 diesel announcement is reported in tons (AP/ABC30: "more than 300,000 tons"), while the New York Post says
  "hundreds of thousands of gallons". The live article repeats the gallons wording. `NumberUnitCheck` now reports this as an unresolved unit
  discrepancy (critical). The revision drops the figure.
- Quotation check: "There's been no pause. Zelensky should be focused..." IS one continuous passage in the New York Post account; the verifier passes it.
  The original Rubio remarks could not be fetched, so this verifies the quote against the Post's report, not the transcript.
- Revisions: #50 (blocked by the excerpt rule), **#51** (`pending_review`, fact-check ready: leads with the allegation, keeps Sept. 22 and Oct. 19 statements
  separate, ends on the unresolved ship question), #52 (an extra attempt; the fact-check correctly held it as `needs_research` because it moved the
  ship quotes onto the Monday Uzbekistan appearance). #51's excerpt was rewritten by hand because it repeated the lead (recorded in its change log).
- Not found: no Breitbart original or Fox News transcript was retrievable; those statements are credited to the New York Post, ABC News and Kyiv Post.

## Cost (estimated from recorded usage at published prices: gpt-5.4 $2.50/$15, gpt-5.4-mini $0.75/$4.50 per 1M)
Revisions #49 $0.054, #50 $0.100, #51 $0.056, #52 $0.035 = **$0.244**. Dry runs: $0. Brave: no extra searches were made (the new queries run only in the live pipeline).

## Limits
- Source relationship detection is heuristic (wording overlap, attribution phrases, a host list); a rewritten copy with no attribution can slip through.
- Number checks only see numerals and "hundreds/thousands of <unit>" phrases and the units listed above.
- Quote attribution is checked against the sources supplied, not against original audio or transcripts.
- Nothing is deployed; production still runs the previous code.
