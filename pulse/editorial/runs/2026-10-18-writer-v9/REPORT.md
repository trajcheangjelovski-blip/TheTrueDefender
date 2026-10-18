# Writer v9 experiment: report (2026-10-18)

Scores are provisional editorial judgments (1-5), not AI-detector results and not a prediction about AdSense.
Three articles are an initial test, not proof of consistent quality.

## Drafts (all unpublished; originals untouched)
| Article | Writer output | Sources (exact URLs in each draft file) |
|---|---|---|
| Evacuation opinion (post 5652) | `drafts/5652-evacuation.run1.md`: **needs reporting**, no draft | MyNewsLA, LAist (Cal Fire page and Voice of OC were fetched by me but not by the server's extractor) |
| ABA (post 5640) | `drafts/5640-aba.run1.md` (first), `drafts/5640-aba.run2-revision.md` (one permitted revision) | Higher Ed Dive x2, Fox News, Fox Business |
| El Nino (post 5621) | `drafts/5621-elnino.run1.md` (final test, no tailoring after run 3) | NOAA CPC discussion, Governor's release, NY Post, plus two citation-only Science links |

Everything in `drafts/` and `outputs/` was produced by the improved writer. The two examples in `resources/editorial/examples.md`
were hand-written by an editor/assistant; Example B was modeled on the evacuation case (so run 1 shows the mechanism works, not
that the model finds the problem unprompted).

## Facts I verified independently (free web checks, 2026-10-18)
- Norte Fire: Cal Fire incident page says evacuation orders for San Clemente and San Juan Capistrano were lifted effective Oct. 17 at 6 p.m.;
  MyNewsLA says the same. The column ran Oct. 18 (11:00 a.m. ET). No source reviewed reports pressure to reopen. LAist says "most" orders were lifted and Via La Mirada stayed closed.
- ABA: staff recommended denial (Aug. 2026); NACIQI met Sept. 23-24, split 8-8 twice then voted 11-1 that it could not recommend; Under Secretary Kent has 90 days (Higher Ed Dive, Sept. 25). The published article still called a September hearing "upcoming."
- NOAA CPC discussion issued Oct. 8, 2026: strong-to-very-strong El Nino >83% through Jan-Mar 2027; historic event 54% Sep-Nov, 83% Oct-Dec, 70% Nov-Jan; next issuance Nov. 12. The September discussion (Sept. 10) had 75% / >90% figures that the published article cited via the LA Times.
- Study: Cole et al., "Recent strengthening of eastern Pacific ENSO in the last millennium paleorecord," Science, DOI 10.1126/science.ady2660 (Galapagos corals). The published article linked DOI ...adj3702, which I could not match to this study. Science.org returned 403 to the server, so the writer only had a citation packet.

## Before and after
| | Evacuation opinion | ABA | El Nino |
|---|---|---|---|
| Accuracy / freshness | Orig 1: argued for orders already lifted. New 5: correctly refused to write. | Orig 2: stale hearing language. Run 1 3: still stale, 2 misattributions. Run 2 3: fixed attributions, invented "Sept. 25" (caught by new DraftCheck), hearing quote still undated | Orig 2: stale Sept. forecast figures, unverifiable DOI. New 3: leads with Oct. NOAA data; still adds the governor's Sept. 90%/75% figures next to NOAA's Oct. figures, undated |
| Evidence / attribution | Orig 1 (no sources recorded). New 5 (cites packets) | Orig 3 (Wikipedia links for current-event facts). Run 1 4 (links, some misattributed). Run 2 2 (no links) | Orig 2. New 3: attributed, but no inline links, study not linked |
| Added value | Orig 1. New: names the gap and suggests an angle | Orig 3. New 4: staff finding, NACIQI outcome, federal-aid figures with the conflict between sources | Orig 2. New 3: NOAA observed indices vs probabilities |
| Structure / headline | Orig 2. n/a | Orig 3. New 3 ("weighs" understates "recommended denial") | Orig 3. New 4 (headline names NOAA, matches lead) |
| Prose | Orig 2 (repetitive). n/a | Orig 3. New 3 (six "Fox News reported" in run 1; 4 in run 2) | Orig 3. New 4 |

Automatic-failure check (fabricated quote, unsupported serious allegation, invented source): none found in the three writer drafts;
`ExactQuoteVerifier` and `NamedEntityVerifier` returned no findings. Not checked by a model fact-checker (request cap).
One non-quote fabrication-type error: the "Sept. 25" date in ABA run 2.

## Usage and cost (actual, from API usage fields; gpt-5.4-mini $0.75 in / $4.50 out per 1M tokens, developers.openai.com/api/docs/pricing, checked 2026-10-18)
4 paid requests (limit 4): 44,181 input + 3,212 output tokens = **$0.0476** (limit $1.00; internal ceiling $0.50 enforced in code).
No retries, no paid tools, no web search. Evacuation 9,199/170 ($0.0077); ABA 12,163/1,161 ($0.0143); ABA revision 12,452/906 ($0.0134);
El Nino 10,367/975 ($0.0122). Zero-call dry runs fetched packets for free. Reasoning tokens would be inside completion tokens (none visibly large).
The 4-request cap meant no model fact-check was run on any draft.
