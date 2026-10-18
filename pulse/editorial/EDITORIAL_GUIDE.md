# Editorial guide - writer policy v2026.10.18-13

The active policy is resources/editorial/policy.md, loaded through
App\Support\EditorialPolicy::forPrompt(). New-story and revision requests also load
resources/editorial/examples.md. editorial/ALL_AI_RULES.md is a reference snapshot, not a
runtime prompt file. House settings and learned style are subordinate to the policy.

1. Lead with the clearest verified development and deliver the headline's promise. Add useful
   detail without mechanically repeating the headline, excerpt and opening.
2. Choose structure and length for the story. No padding, mandatory opinion, stock ending or
   forced heading or link quota. A publication floor does not justify inventing material.
3. News is factual; opinion is labeled and has verified premises. Arguments about future or past
   decisions must be framed honestly. Never manufacture a current controversy.
4. Never invent facts, quotations, interviews, eyewitness accounts, motives, credentials or our
   own outreach. Preserve allegation qualifications and procedural status.
5. Match every material claim to actual evidence. Verify the specific source passage, subject
   and date, not merely that the URL works. Multiple articles from one publisher are distinct.
6. Credit original reporting and interviews. Cite a primary document directly only after its
   contents were checked. Reattribute when needed without repeating an outlet in every sentence.
7. Compare definitions, scope and dates before reconciling figures. Use the latest applicable
   measure; explain meaningful changes. Attribute genuine conflicts or omit nonessential disputed
   details and record the gap. Never invent the reason for a discrepancy.
8. Distinguish event, publication, update and forecast-issue times. Do not infer an event date
   from a publication date. Identify a probability's issuer, measure and forecast period.
9. Keep precise inline evidence links; public source lists remain prohibited. Internal links must
   explain the anchor and surrounding point. Omit unrelated links.
10. Add verified explanatory or practical value. Changed wording, a link or a generic opinion
    alone is insufficient. Never disguise aggregation as an independent investigation.
11. Preserve historical reporting cutoffs, date later developments separately, and disclose
    material corrections. Flag material unsupported-claim removals for an editor.
12. Essential gaps return needs_reporting and an empty body; record nonessential uncertainties
    in reporting_gaps. A ready draft, high SEO score or passing code check is not proof of truth.

## Checks and their limits

- Deterministic checks can identify allowed URLs, empty fields, malformed output, some name/date
  or quote mismatches, stock phrases and missing links. A source count does not prove independence;
  a permitted URL or matching publisher name does not prove support for an adjacent claim.
- Model reviews can suggest factual issues, chronology problems and repetition. They remain
  fallible and cannot establish verification of a page they did not receive.
- An editor resolves disputed evidence, material corrections, sensitive allegations, reader value
  and final publication readiness. Store findings separately from public copy.
- Existing controls include SourcePacket, ExactQuoteVerifier, NamedEntityVerifier, DraftCheck,
  PublishGate, FactChecker, and AiClient caps. This policy edit does not add new deterministic
  enforcement or change production settings.

## Improvement without unnecessary API spending

Preserve concise failure/correction examples and prompt versions. Evaluate on held-out topics
as well as the observed failures. Keep evaluation-only fixtures out of runtime examples. Reuse
cached source packets and make targeted revisions. Preserve configured call and spending caps;
count retries, reviews and paid tools. Do not automatically expand testing or claim a professional
quality threshold has been proven by familiar examples. No paid generation is needed merely to
verify that the policy file is loaded into a request.
