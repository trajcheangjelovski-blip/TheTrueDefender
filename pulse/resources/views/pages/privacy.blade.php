@extends('layouts.app')
@section('title', 'Privacy Policy — TheTrueDefender')
@section('content')
  <main class="page-main">
    <h1>Privacy Policy</h1>
    <p class="page-sub">Storage, optional measurement and information you submit.</p>
    <h2>Essential storage</h2>
    <p>Session and security cookies support forms, sign-in and request protection. The ttd_privacy preference cookie records your optional choices for up to 180 days. Local storage remembers interface preferences, dismissed prompts, subscriptions, followed topics and quiz progress. Browser notification subscriptions are separate from cookie preferences.</p>
    <h2>Optional analytics</h2>
    <p>Google Analytics loads only after analytics consent. When configured, it measures site usage. First-party measurement records page paths, a random browser identifier, device type, user agent and country where available, plus headline clicks and impressions. These optional requests and identifiers are gated by analytics consent. Inactive presence records may be removed after seven days; this does not establish retention for all other analytics data.</p>
    <h2>Affiliate referrals</h2>
    <p>Optional referral tracking credits participating affiliates. With referral consent, an aff_ref cookie can store a referral code for the configured attribution window (default 30 days). Referral records can include a IP/device-derived identifier and visit details. Without consent the referral cookie is cleared and referral visits are not recorded for attribution.</p>
    <h2>Advertising</h2>
    <p>Advertising delivery, including AdSense and custom placement code, is paused pending configuration and testing of an advertising consent integration. These first-party preferences are not a Google-certified CMP. If advertising is enabled later, this policy and the consent integration must identify the actual vendors and choices first.</p>
    <h2>Email, notifications and forms</h2>
    <p>Submitting a subscription form stores your email address and signup information for email briefings when available. Delivery uses the configured mail provider (SMTP or Resend). Unsubscribe links are included in emails. Browser notifications require a separate signup and browser permission; subscriptions store delivery endpoints and keys. Revoke permission through your browser settings. Comments, polls, contact forms and orders store the information needed to provide those requested features.</p>
    <h2>Other services</h2>
    <p>Page fonts are requested from Google Fonts, which receives connection information such as IP address. Hosting and security infrastructure may retain operational logs. Payment features, if enabled, may use Stripe; payment data is sent to its service when using those features.</p>
    <h2>Your choices and questions</h2>
    <p><button type="button" class="cookie-btn cookie-decline" data-cookie-settings>Change cookie preferences</button></p>
    <p>Withdrawing analytics consent stops optional measurement and reloads the page to stop previously loaded scripts; accessible Google Analytics cookies and the local measurement identifier are removed. Withdrawal does not delete historical records already collected. For privacy and deletion questions, use our <a href="{{ route('page', 'contact') }}">contact page</a>.</p>
    @include('partials.publisher')
  </main>
@endsection
