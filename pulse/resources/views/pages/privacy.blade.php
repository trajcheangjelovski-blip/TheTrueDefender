@extends('layouts.app')
@section('title', 'Privacy Policy — TheTrueDefender')
@section('meta_description', 'How TheTrueDefender collects, uses and protects information, and the choices you have.')

@section('content')
  {{-- Advertising wording follows whether any ad placement is actually enabled, not whether the AdSense code is installed. --}}
  @php $adsOn = \App\Models\AdPlacement::where('is_enabled', true)->exists(); @endphp
  <main class="page-main">
    <h1>Privacy Policy</h1>
    <p class="page-sub">Last updated: October 18, 2026</p>

    <h2>1. Who we are</h2>
    <p>This website, thetruedefender.news, is published by TheTrueDefender. For any privacy question or request, write to <a href="mailto:support@thetruedefender.news" style="color:var(--accent-2)">support@thetruedefender.news</a>.</p>

    <h2>2. What we collect, why, and who receives it</h2>
    <p>We collect only what the features below need. Items marked <em>optional</em> run only where you allow them or where the law does not require your permission first (see section 4).</p>
    <ul>
      <li><strong>Newsletter.</strong> If you sign up for the Morning Brief we store your email address, an optional name, and the form you used. We use it to email you the newsletter. Emails are sent through our email provider, Resend. Each email has an unsubscribe link; after you unsubscribe we keep a record that you did so, so we do not email you again.</li>
      <li><strong>Browser notifications.</strong> Only if you choose to turn them on, we store your browser's push address and encryption keys and the topics you follow, and use them to send you alerts. They are delivered through your browser's push service (for example Google, Apple or Mozilla). You can turn notifications off in your browser at any time. This permission is separate from cookies and from the newsletter.</li>
      <li><strong>Comments.</strong> When you post a comment we collect your first name, surname, email address, phone number and the comment. Your first name and surname are shown publicly with the comment. Your email address and phone number are not shown. We also store a one-way hash of your IP address to help limit abuse. Comments are screened by an automated moderation service (OpenAI), and may be held for review before they appear; the text of the comment is sent to that service for this purpose.</li>
      <li><strong>Contact page.</strong> The form on our Contact page currently checks what you enter but does not save or forward it. To reach us, email the address above.</li>
      <li><strong>Reader polls and the news quiz.</strong> Your votes are counted on our server. Your quiz answers and quiz progress stay in your browser.</li>
      <li><strong>Security and hosting.</strong> Our servers and our CDN and security provider, Cloudflare, process technical data such as your IP address, browser type and the pages you request in order to deliver the site, block abuse and diagnose problems. We also use a session cookie and a security (CSRF) cookie so forms and comments work; these are strictly necessary.</li>
      <li><strong>Fonts.</strong> Page fonts are loaded from Google Fonts, so Google receives your IP address when a page loads.</li>
      <li><strong>Optional: our own reader statistics.</strong> To count active readers and which headlines are clicked, we store a random identifier in your browser and, with each visit, the page you are on, device type (mobile, tablet or desktop), your country (taken from the Cloudflare network header) and your browser's user-agent text. The identifier does not name you. Active-reader records are removed after a week without activity.</li>
      <li><strong>Optional: Google Analytics.</strong> We use Google Analytics 4 to understand how the site is used. It sets cookies and reports pages viewed, approximate location and device information to Google.</li>
      @if($adsOn)
      <li><strong>Optional: advertising.</strong> We show ads through Google AdSense. Google and its partners may use cookies and similar technologies to serve and measure ads, including ads based on your visits to this and other sites.</li>
      @endif
      <li><strong>Saved in your browser (local storage).</strong> Whether you subscribed or dismissed a prompt, which topics you follow, your quiz progress and, if you allow analytics, a visit count and last-visit time. This stays on your device; clear it in your browser settings.</li>
    </ul>
    <p>We do not sell your personal information. We have no shop or affiliate program: they have ended, and our records contain no shop orders or affiliate accounts.</p>

    @unless($adsOn)
      <h2>Advertising</h2>
      <p>We do not currently display advertisements. If we introduce advertising, we will update this privacy policy to explain the advertising providers involved, the information they collect, and the privacy choices available to readers before those services are enabled.</p>
    @endunless

    <h2>3. How long we keep it</h2>
    <ul>
      <li>Newsletter address and push subscription: until you unsubscribe or ask us to delete them (a minimal unsubscribe record is kept).</li>
      <li>Comments and the details you gave with them: until you ask us to remove them.</li>
      <li>Active-reader records: removed after about a week without activity. Aggregated counts of views and clicks, which do not identify anyone, are kept.</li>
      <li>Session cookie: expires after a period of inactivity (two hours).</li>
      <li>Server and security logs, and data held by Google, Cloudflare and Resend: kept under those providers' and our own operational rules for as long as needed for security and troubleshooting.</li>
    </ul>

    <h2 id="choices">4. Your choices</h2>
    <ul>
      <li><strong>Cookies and analytics{{ $adsOn ? ', and ads' : '' }}.</strong> If you are in the European Economic Area, the United Kingdom or Switzerland, or we cannot tell where you are, optional analytics{{ $adsOn ? ' and advertising' : '' }} do not start until you agree through the privacy message, and closing the message or scrolling is not agreement. Elsewhere they run unless you opt out. You can <a href="#choices" data-privacy-choices style="color:var(--accent-2)">reopen your privacy choices</a> at any time from this link or the "Privacy choices" link at the bottom of every page. If the privacy message cannot be shown, you can still turn off cookies in your browser, use the Google tools below, or write to us.</li>
      @if($adsOn)
      <li><strong>Ads.</strong> Manage personalized ads at <a href="https://adssettings.google.com" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">Google Ad Settings</a>, or opt out of some third-party vendors at <a href="https://www.aboutads.info/choices" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">aboutads.info</a>.</li>
      @endif
      <li><strong>Newsletter and notifications.</strong> Use the unsubscribe link in any email. Turn off notifications in your browser's site settings.</li>
      <li><strong>Access, correction and deletion.</strong> Depending on where you live, you may have the right to ask what we hold about you, to correct or delete it, to object to or restrict some uses, and to complain to a data protection authority. Email us and we will respond as the law requires. Please give the email address you used so we can find your records.</li>
    </ul>

    <h2>5. How Google uses information</h2>
    <p>Google Analytics{{ $adsOn ? ', Google AdSense' : '' }} and Google Fonts are Google services. Google describes how it uses information from sites that use its services at <a href="https://policies.google.com/technologies/partner-sites" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">policies.google.com/technologies/partner-sites</a>@if($adsOn), and explains its advertising technologies at <a href="https://policies.google.com/technologies/ads" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">policies.google.com/technologies/ads</a>@endif. Google's own privacy policy is at <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">policies.google.com/privacy</a>.@if($adsOn) Where required, consent choices for Google's services are collected through Google's Privacy &amp; messaging tool.@endif</p>

    <h2>6. Service providers and transfers</h2>
    <p>We rely on Google (analytics, fonts{{ $adsOn ? ', advertising, consent messages' : '' }}), Cloudflare (CDN and security), Resend (email delivery), OpenAI (comment moderation) and the browser push services named above. These providers may process data outside your country, including in the United States.</p>

    <h2>7. Security</h2>
    <p>We use reasonable technical and organizational measures to protect the data we hold. No system is completely secure.</p>

    <h2>8. Changes to this policy</h2>
    <p>We will update the date at the top when this policy changes in a meaningful way.</p>
  </main>
@endsection
