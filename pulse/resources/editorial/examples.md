---
version: 2026.10.18-2
---
Worked examples teach editorial judgment, not a fixed article template. Example A uses the
historical report identified below; B-D are illustrative evidence fixtures, not claims about live
events or records of independent reporting. This revision is AI-assisted and awaits editorial
approval. Examples never override the policy. Do not copy fixture facts or URLs into real articles.

EXAMPLE A. Lead and consequence paragraph (straight news).
Evidence: Higher Ed Dive, Sept. 25, 2026,
https://highereddive.com/news/federal-advisory-panel-dodges-decision-on-abas-accreditation-arm/831329
The advisory panel voted 11-1 to report no recommendation after deadlocking; Nicholas Kent has
90 days to decide on federal recognition. State courts generally set bar-eligibility requirements.
WEAK: "The coming decision could change everything about becoming a lawyer. That separation
between federal recognition and state licensing is what makes the decision significant."
BETTER: "A federal advisory panel declined to recommend whether the American Bar Association's
law school accreditor should retain federal recognition, leaving the decision to Under Secretary
of Education Nicholas Kent, <a href="https://highereddive.com/news/federal-advisory-panel-dodges-decision-on-abas-accreditation-arm/831329">Higher Ed Dive reported</a>.
Kent has 90 days to decide. Losing recognition would affect the council's federal student-aid
role; it would not itself rewrite state rules on eligibility for the bar exam."
Judgment: name the actual development, link the report that contains it, and explain the useful
distinction early. A report published before the meeting cannot substantiate the outcome.

EXAMPLE B. A false opinion premise requires needs_reporting.
Illustrative task: urge officials to keep a wildfire evacuation order in place as of Oct. 18.
Packets: an incident update and independent local coverage say that order was lifted Oct. 17.
Neither packet establishes pressure for an early reopening.
RIGHT OUTPUT: status "needs_reporting"; body ""; reporting_gaps:
["The requested premise treats an order already lifted as still in force.",
 "No supplied evidence establishes pressure for an early reopening.",
 "Choose and research an accurate current angle, or explicitly frame a retrospective opinion."]
WRONG: a polished column inventing pressure and urging officials to retain an order that ended.
Judgment: persuasive style cannot repair a false premise. A clearly framed retrospective argument
is different from misrepresenting the present status.

EXAMPLE C. Credit the interview and avoid repetitive attribution.
Illustrative packets: BBC interviewed a named official about a proposal. A separate retrieved
agency document describes the proposal's scope. The document contains no interview.
RIGHT: attribute the interview to the BBC and link that interview; attribute the scope to the
agency document and link it separately. Continue a clearly attributed passage naturally, then
identify a new source when moving to the next claim.
WRONG: credit the official's interview answer to the agency document, imply the official spoke
to us, or start every sentence with "BBC reported". A document's existence does not establish
that its contents were checked.
Judgment: preserve both who spoke and who obtained the interview. Direct document verification
can support public facts without erasing another outlet's original reporting.

EXAMPLE D. Counts and links require editorial judgment.
Illustrative packets: an August report estimates over a dozen institutions are affected; a
September hearing report quotes a representative saying five depend on the relevant recognition.
No packet explains whether the figures describe the same population.
RIGHT: use the newer representative's precisely attributed figure for what it establishes; omit
the older estimate if nonessential and record the unresolved scope difference in reporting_gaps.
If the comparison is essential, identify both definitions and the unresolved discrepancy.
WRONG: state both figures as equivalent, claim the number fell without evidence, or assume the
newer article proves the older one was wrong. Link each claim to its own report, even when both
reports have the same publisher. Omit an unrelated internal link rather than meeting a quota.
