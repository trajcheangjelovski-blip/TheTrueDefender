# Implementation review — October 16, 2026

This branch changes the existing Laravel site in `pulse/`. It is not a rebuild. Nothing was deployed, no live content was rewritten or deleted, and no account settings were changed. The migration adds nullable/default fields and does not reclassify existing articles or rename URLs.

## Findings and changes

- Live review initially showed one story duplicated in the hero, trending, morning brief, most-read and category modules; a second story appeared during inspection. The code had separate independent queries selecting the same rows, and navigation did not check for published articles. The homepage now allocates each story once across editorial modules. Empty categories are absent from nav/footer; their existing URLs are preserved with empty states. One-story and zero-story states are supported. The ticker remains a navigation aid and can reference a featured story.
- No production database was available. Actual totals, hidden drafts, scheduling and retired items cannot be inferred from public pages. `php artisan content:inventory` is a new read-only diagnostic for an authorized operator.
- Sources already existed as JSON but were deliberately hidden and mostly not editable. Source title, publisher, URL and attribution notes are now editable and displayed in a native keyboard-accessible expandable list. Legacy `name/url` and original `source_name/source_url` records are supported; unsafe URLs and duplicates are excluded from display. Storing a source does not certify its accuracy or original record access.
- The live Publix anchor pointed to an unrelated CVS opioid story. Existing article-to-article links now require an explicit editor selection and a currently published, non-future destination. Unapproved/missing links become plain text while stored content and URLs stay intact. Automatic AI link insertion and same-category link quotas were removed. Editors must review semantic relevance; no keyword algorithm certifies it.
- News, Analysis and Opinion have separate model/editor/article/card labels and schema types. Legacy Opinion categories retain their label. Existing Our Take headings are displayed explicitly as Opinion; the substantive news rewrite stays private. News generation no longer suppresses publication attribution or automatically appends commentary; a news publish check rejects Our Take headings. Automatic research/SEO revisions of published stories are staged in private draft fields. Generated standalone opinions are drafts.
- Unknown author profile details, job titles and credentials are withheld. Confirmed-person and confirmed-pen-name states are explicit; pen names need a public disclosure. Existing names/URLs stay available with an unverified-profile notice. No assertion is made that Daniel Hayes or Nathan Reed is a real person or pen name. Neutral team wording is prepared in `PUBLIX_REVIEW.md` for owner review. Publisher/editor/public-contact fields start blank.
- About, Newsroom and Editorial Standards describe observed AI-assisted and automatic publishing paths instead of asserting mandatory human fact-checking. They distinguish intended editorial standards from actual verification. No new named newsroom, credentials, ownership or staff location was invented.
- Image captions, visible credits, source URL, type and private permission evidence are editable. Unknown provenance and rights are flagged. New generated assets use a distinguishable path and an AI-generated label; existing asset origins are not guessed. A watermark never establishes rights.
- Newsletter copy makes no fixed five-story/daily-delivery promise. Signup forms are shown only when digest and a delivery transport are configured. Quiz teasers require a structurally valid current-day quiz and show its real question count; unavailable quizzes return a clear 404. Quiz-reminder promises were removed because that delivery feature does not exist.

## Consent and service inventory

Before this branch, GA loaded immediately, AdSense loaded on idle/interaction, custom ad HTML rendered directly, first-party presence and click tracking ignored choices, and affiliate referrals could set `aff_ref`. Cookie acceptance called notification permission.

Now:

- Notification permission is requested only from a separate explicit notification signup action.
- First-party versioned cookie preferences default analytics/referrals off. Legacy `dp_consent` acceptance is not treated as valid optional consent.
- Analytics script loading and first-party page/click/presence measurement require analytics consent on both relevant client and server paths. Affiliate visit recording, attribution cookies and checkout credit require referral consent. Withdrawal clears accessible tracking identifiers/cookies and reloads to stop previously loaded optional scripts.
- A persistent footer control reopens purpose choices. Necessary sessions/security and requested interface features remain available. Google Fonts is still requested for the existing design; its connection data is disclosed. Email uses configured SMTP/Resend; enabled checkout can use Stripe.
- **No existing certified CMP was found. Ad delivery is disabled in code, including arbitrary custom placement HTML, until a real advertising-consent integration is configured and tested.** The first-party banner is not represented as a certified CMP. Reuse the account-generated Google Privacy & messaging integration, or a suitable currently certified CMP, once account access/configuration is provided. Do not just turn ad delivery back on. The final vendor list, consent signals, regional operation and real script/cookie audit must be tested in staging with the actual CMP and ad account.
- Historical analytics retention, actual production infrastructure, mail delivery, ad-account settings, image licenses and the publisher identity still require owner confirmation. The privacy page describes code behavior and identifies the public contact route without inventing an operator identity.

## Official requirements versus editorial recommendations

Official sources checked October 16, 2026:

- [AdSense Program policies](https://support.google.com/adsense/answer/48182?hl=en) and [Google Publisher Policies](https://support.google.com/publisherpolicies/answer/10502938?hl=en): applicable policies, including misleading representation and low-value/replicated content, must be followed. Do not treat cosmetic changes as approval or factual verification.
- [Site readiness guidance](https://support.google.com/adsense/answer/7299563?hl=en): original, useful content and usable navigation. No minimum article count, word count or traffic threshold is invented here; existing application thresholds are internal house settings.
- [Publisher consent requirements](https://support.google.com/adsense/answer/13554116?hl=en): Google requires a certified CMP integrated with the IAB TCF for personalized ads in the EEA, UK and Switzerland. Consent Mode or this custom preference panel alone is not a substitute. The Google page distinguishes certified/non-certified traffic and possible ad-serving modes; a blanket claim that all modes always require the same integration would be inaccurate.
- [EU User Consent Policy](https://www.google.com/about/company/user-consent-policy/): consent, disclosures and revocation must reflect actual processing. Configuration and legal applicability need review for the actual audience and vendors.
- [Shocking content restriction](https://support.google.com/publisherpolicies/answer/10437538?hl=en): graphic accounts or imagery can restrict ad demand. This is not a blanket ban on legitimate crime reporting. The draft reduces unnecessary graphic detail; content-specific review still matters.

Source disclosure, clear article labels, neutral biographies, and a review draft are editorial recommendations and transparency improvements. They do not guarantee advertising eligibility.

## Safe review workflow

After reviewing the branch in staging, an operator may run migrations and view/cache checks there. Do not run the old wipe/prune instructions in historical audit documents.

The JSON package can be inspected without writes:

```sh
cd pulse
php artisan posts:stage-review ../editorial-review/publix-review-draft.json
```

`--store` only fills private `draft_*` fields and notes, refuses an existing review draft, and never replaces the live body, title, status, slug or publication time. The prepared package is not automatically imported by the migration. Do not publish it before the flagged records are checked.

## Before applying for AdSense, in priority order

1. Verify the Publix case-specific affidavit/orders and current docket, resolve the identified claims, then manually review the draft and source attributions. Confirm author identities, responsible publisher/editor and image permissions.
2. Configure and test the real certified CMP, advertising vendors, privacy disclosures and consent/revocation behavior with account access in staging. Keep ads held until that is complete.
3. Review the actual inventory and automatic publishing/update paths. Publish only useful, supported content and test newsletter delivery/current quiz behavior. No filler, invented audience metrics, deletion campaign or arbitrary content quota is recommended.
4. Review and merge the tested branch through your normal release process, verify production behavior after a separately authorized deployment, and assess the complete live site before requesting a new review. Approval remains Google's decision.


## Validation results

- PHP 8.3: 277 application/migration/config files passed syntax checks before final additions; all changed/new PHP files were checked again at completion.
- PHPUnit: **11 tests, 60 assertions, all passed**. Coverage includes sparse navigation, source escaping and unsafe URLs, draft isolation, internal destination selection and publication state, future article access, unverified/pen-name profiles, purpose-specific measurement consent, current quiz validation, review import safety, and the actual admin editor form.
- Blade view cache compiled successfully. Four changed public JavaScript files passed Node syntax checks. `npm run build` passed using the existing frontend dependencies; no new runtime dependency was added.
- Local browser checks passed at **1440 × 1000** and **390 × 844**, using an isolated fixture database. Checked the homepage, article, author profile, expanded source list, image disclosure, canonical URL, missing/irrelevant internal anchor removal, no private draft leakage, no horizontal overflow, cookie customization and rejection/withdrawal. No uncaught JavaScript errors remained.
- Browser network checks intercepted optional external endpoints instead of sending real analytics: no Analytics/AdSense request before a choice, Analytics loads after explicit analytics consent, no new Analytics load after withdrawal/reload, and **zero notification permission requests from cookie choices**. This does not test a real CMP, personalized ads, actual mail delivery, or production accounts.
- Browser verification found and fixed a missing-slider-button error for one-story inventories and an overlapping mobile image-type label. A null article description also exposed an existing Blade section output-buffer issue; blank descriptions now render safely.
- `git diff --check` passed. Existing dependencies emit PHP type warnings, and Vite reports an optional font-fallback optimization package is absent; neither prevented the completed checks. Dependency upgrades were outside this task.

These checks use local fixtures, not a production data export. Production database inventory, actual cookie/vendor behavior after integration, real image licenses, identities and factual case records remain unverified.
