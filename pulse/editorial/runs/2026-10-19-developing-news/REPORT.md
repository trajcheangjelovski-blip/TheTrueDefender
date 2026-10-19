# Developing-news upgrade report (2026-10-19)

Regression case: post 5705, "Zelenskyy says Ukraine got no warning before Russian strike" (Oct. 18-19, 2026). Additive on top of the existing pipeline
(Brave, gpt-5.4 research, gpt-5.4-mini writer, gpt-5.4 fact-check, final check, sections OFF). Nothing removed or replaced.

## What was added
| Area | Change | Files |
|---|---|---|
| Timeline (1) | The research package now carries `timeline` (event, official_statement, warning, siren, development, source_publication, source_update), each with its own time. Rule: a publication or update time is never the event or statement time | `Services/ResearchAnalyst.php` |
| Changing figures (2) | `figures` (what is counted, unit_type people/households/customers/buildings/facilities/sites/missiles/drones, scope, as_of, issuer, status, relation). `figureNotes()` classes pairs as compatible / supersedes / unclear / genuine conflict. `FigureCheck` makes a superseded or unverified figure stated as current CRITICAL | `ResearchAnalyst.php`, `Support/FigureCheck.php` |
| Count nouns (2) | `NumberUnitCheck` reads "28 substations", "more than half a million people", "16 ballistic missiles". People, households, customers, buildings, facilities, missiles and drones are different units: the same number under another noun is CRITICAL. Unit mismatch is compared inside a family only | `Support/NumberUnitCheck.php` |
| Waves (3) | `phases` with per-statement `warned` (yes/no/late/unknown). `phaseConflicts()` reports a genuine disagreement only when the SAME phase has both yes and no. Waves, siren times and who said what reach the writer and the fact-checker | `ResearchAnalyst.php`, `Services/FactChecker.php` |
| Original sources (4) | `WebResearcher::officialQueries` (state.gov, president.gov.ua, Ukrainian Air Force, Russian Defense Ministry, Pentagon, White House, Treasury) run before the quote/people/number queries; `research_extra_queries` default 2 to 3; official Ukrainian, Russian and US hosts count as primary. A later copy of the same reporting is told it is probably a LATER UPDATE (a newer figure), not an unverified claim | `Services/WebResearcher.php`, `Services/SourcePacket.php` |
| Official claims (6) | `claims` (asserter, independently corroborated?) printed to the writer and checker as "attribute to the asserter". Fact-check rule: an official's explanation (a software problem) is not the verified cause | `ResearchAnalyst.php`, `FactChecker.php` |
| Quotations (5) | The existing `ExactQuoteVerifier` already catches joined remarks and wrong speakers (added in the previous upgrade). Research quotes now carry occasion and date. Tests extended | tests |
| Focus and ending (7) | `LeadCheck::unrelatedEnding`: a last paragraph that shares under 30% of its words with the headline and first paragraphs AND is marked as a side topic or brings a new large number. Policy relevance test (explains the event, context, dispute, related development, consequences) | `Support/LeadCheck.php`, `PublishGate.php`, policy |
| Links (8) | `LinkQuality`: Wikipedia links removed from news (setting `link_wikipedia` can allow them); applied to writer output and link enrichment; gate finding `wikipedia-link` | `Support/LinkQuality.php`, `Rewriter.php`, `LinkEnricher.php` |
| Repetition (9) | `excerpt-restates-headline`; stricter excerpt vs lead (80% containment); `near-duplicate-sentence` (same wording AND same figures; sentences that differ in a number are exempt) | `LeadCheck.php`, `PublishGate.php` |
| Fact-check (10) | gpt-5.4 prompt rules: developing events, waves and stages, official claims, relevance. Figure findings merge into the verdict; critical still keeps a story out of automatic publication | `FactChecker.php` |
| Policy | 2026.10.19-3: six additive bullets (timeline, figures, waves, official claims, background relevance, links). Earlier rules are asserted still present by test | `resources/editorial/policy.md` |
| Revision tool | `posts:revise-from-packets --with-research` runs the research desk first and hands the package to the writer and the fact-checker | `Console/Commands/PostsReviseFromPackets.php`, `Services/EditorialPipeline.php` |

## Existing rules preserved
Brave Search; gpt-5.4 research, gpt-5.4-mini writer, gpt-5.4 fact-check (unchanged, asserted by test); freshness and event-date validation; SEO; publication gate and
approvals; revision history; Key Takeaways, Our Take and Sources sections OFF (asserted); house-voice notes; no standalone sources list.

## Tests
163 passed (888 assertions): the earlier 152 plus `DevelopingNewsTest` (figures by time, scope and category; count nouns; first versus second wave; genuine versus compatible;
quotations and speakers; official claims; source originality; official-source queries; unrelated ending; excerpt repetition; Wikipedia links; the fact-check prompt and model;
existing rules). My near-duplicate-sentence check first tripped templated fixtures in older tests; it now ignores sentences that differ in a number, which is also right for figures.

## The Zelenskyy article (post 5705)
Findings against the live article, checked against the Guardian, NBC, NY Post, LA Times and Kyiv Post as fetched by the server:
- No confirmed factual error in a name, date or quotation. The problems were editorial: the earlier count ("more than 10 facilities", NBC) and the later count (28 substations, NY Post and
  LA Times) sat side by side with no time order; the waves were framed as "the accounts differ" although they describe different stages; Rubio's software-problem remark sat next to the
  attack as if explanatory; the ending jumped to Ukraine's 1,000-drone attack; Rubio was linked to Wikipedia; the excerpt repeated the lead.
- The NY Post and LA Times are later versions of the wire story NBC carries (43% identical wording), published 3 to 5 hours after NBC. That is why the 28-figure first looked unverified: the
  first revision attempt (#53) dropped it. The later-update rule fixes that.
- Draft revisions saved (the live article is untouched): #53 (first attempt, blocked by the excerpt rule) and **#54** (`pending_review`, fact-check ready, 0 critical, originality clean). #54 leads
  with Zelenskyy's no-warning statement; states the first wave (no warning) and the second wave (Ihnat: a warning came; NY Post and LA Times: too late for sirens) as compatible stages; shows the
  earlier and later counts in time order; attributes the Russian ministry claim to the ministry; keeps Rubio's remark as his remark; has no Wikipedia link; has an excerpt with a different fact;
  ends on Zelenskyy's closing statement; and drops the 1,000-drone paragraph.
- Not retrievable by the server (HTTP 403): president.gov.ua, the Air Force Telegram, a state.gov transcript of Rubio. Quotations are verified against the outlets' reports, not the originals.
  Ihnat's statements are known only through the Guardian.

## Cost (estimated from recorded usage at published rates)
Revision #53 $0.178 and #54 $0.086, plus one research attempt cut off by a token cap (not recorded; about $0.10 estimated): about $0.36 in all. No other paid calls; Brave not called.

## Limits
Relationship detection is based on wording and attribution. Figure and phase reconciliation depends on the research model filling the structured fields correctly; code validates the logic but not
the extraction. `unrelatedEnding` is a heuristic and can miss a loosely related paragraph in the middle of an article. Nothing is deployed.
