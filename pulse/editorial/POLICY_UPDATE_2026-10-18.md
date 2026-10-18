# Policy update 2026.10.18-13

Scope: improve future writing instructions and synchronize the user-facing rules reference.
This is a local policy/documentation update, not a deployment, model change or live rewrite run.

## Active files

- resources/editorial/policy.md: binding policy, version 2026.10.18-13.
- resources/editorial/examples.md: examples version 2026.10.18-2, explicitly marked AI-assisted
  and awaiting editorial approval. Example A retains its historical report; B-D are fixtures.
- editorial/EDITORIAL_GUIDE.md: short guide, verification limits and economical evaluation.
- editorial/ALL_AI_RULES.md: refreshed copies of the active policy and examples; recorded
  house settings and other service excerpts are identified separately from active policy.

## Preserved requirements

Evidence-only facts, exact quotation wording and provenance, no invented reporting, neutral news,
labeled opinion, careful crime reporting, relevance, optional structure, no padding, accurate SEO,
no public Sources section, inline evidence links, reporting gaps and historical publication cutoffs.
Existing publication gates, model settings and API spending controls are unchanged.

## Resolved conflicts and clarified rules

1. Older rules demanded both publishing every conflicting count and publishing only the newest.
   Compare definitions and times first. Use the latest applicable measure; explain meaningful
   changes, attribute unresolved material conflicts, or omit a nonessential detail with an internal gap.
2. The recorded house setting prohibited outlet attribution. Binding policy now explicitly credits
   original reporting and interviews while allowing checked primary documents and natural attribution.
3. Repeated bans on any repeated word or phrase encouraged awkward leads. Remove redundant
   information while allowing essential names and central facts to recur for clarity.
4. Mandatory linking of an unread study could imply verification. Citation-only metadata verifies
   only the bibliographic information supplied, not the study's results. Secondhand findings retain
   their actual source attribution.
5. A blanket ban on opinion about future events or completed decisions was too broad. Clearly framed
   prospective and retrospective arguments are allowed; a false current premise remains blocked.
6. Deleting an unsupported material assertion is not automatically cosmetic. Flag a material
   correction for an editor; do not invent source agreement, finding numbers or approval.
7. Added passage/date verification, same-publisher citation selection, internal-link relevance,
   issue-date/forecast-period distinctions, final preflight and honest limits on verification.
8. Preserved the ban on generic closing advice while allowing relevant, sourced practical guidance.

## Runtime connection and limitations

EditorialPolicy::forPrompt() reads resources/editorial/policy.md and places it before house voice
notes. Rewriter::writeArticle() includes it for new, revision and fix modes; it includes examples
for new and revision modes. Other article-writing paths also call the policy helper. The policy is
cached within a running PHP process; restart long-lived workers when deploying the changed files.

ALL_AI_RULES.md itself is not loaded by the writer. The house settings and learned guide recorded
there were not fetched from production during this update. Conflicting settings remain subordinate;
no database setting or server was modified.

Some separate code still contains older instructions or heuristics: automatic attribution linking
can select a URL by publisher name, link enrichment uses quotas and broad reference links, and
revision/correction filtering is narrower than the improved policy. These are not claimed as fixed
by this document update. Inspect those controls before relying on automatic source verification or
correction disclosure. Prompt instructions cannot override a program's filtering or publication gates.

No paid API calls are needed for this update. Evaluation outcomes, production activation and any
future live tests must be reported separately from these policy changes.

## Validation performed

- Confirmed that the policy and examples in ALL_AI_RULES.md exactly match the active files.
- Confirmed valid UTF-8, twelve numbered policy sections and no whitespace errors in the diff.
- Inspected the writer's policy/example loading paths; no application PHP files were changed.
- No paid API calls, live rewrites, publication actions or production-setting changes were made.
- Application tests were not run: PHP is not on the local command path and WSL is not installed.
  The static checks above do not establish future model output quality.
