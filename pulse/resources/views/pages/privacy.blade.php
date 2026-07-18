@extends('layouts.app')
@section('title', 'Privacy Policy — TheTrueDefender')

@section('content')
  <main class="page-main">
    <h1>Privacy Policy</h1>
    <p class="page-sub">Last updated: {{ date('F j, Y') }}</p>
    <p><em>This is placeholder text. Replace it with your reviewed privacy policy before launch.</em></p>

    <h2>1. Information We Collect</h2>
    <p>When you subscribe to our newsletter, contact us, or purchase from our shop, we may collect your name, email address, shipping address, and payment information. We also collect standard technical data such as browser type and pages visited to improve the site.</p>

    <h2>2. How We Use Your Information</h2>
    <ul>
      <li>To deliver our newsletter and respond to your messages</li>
      <li>To process and ship shop orders</li>
      <li>To improve our website and content</li>
    </ul>
    <p>We do <strong>not</strong> sell your personal information to third parties.</p>

    <h2>3. Cookies</h2>
    <p>We use essential cookies to keep the site functioning and optional analytics cookies to understand how readers use the site. You can disable cookies in your browser settings.</p>

    <h2>4. Comments &amp; Reader Opinions</h2>
    <p>When you submit a comment, we collect your first name, surname, email address, and phone number. <strong>Only your first name and surname are shown publicly</strong> alongside your comment. Your email address and phone number are kept private, stored securely, and are never published or shared — we use them solely to verify authenticity and contact you if needed. All comments are reviewed before they appear. You may request removal of your comment and associated data at any time via our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a>.</p>

    <h2>5. Advertising</h2>
    <p>We display advertising served by Google AdSense. Third-party vendors, including Google, use cookies to serve ads based on your prior visits to this website or other websites. Google's use of advertising cookies enables it and its partners to serve ads to you based on your visits to this site and/or other sites on the Internet.</p>
    <p>You may opt out of personalized advertising by visiting <a href="https://adssettings.google.com" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">Google Ads Settings</a>, or opt out of some third-party vendors' cookies at <a href="https://www.aboutads.info/choices" target="_blank" rel="noopener noreferrer" style="color:var(--accent-2)">www.aboutads.info</a>.</p>

    <h2>6. Data Security</h2>
    <p>We use industry-standard measures to protect your data. Payment processing is handled by trusted third-party providers; we never store full card numbers.</p>

    <h2>7. Your Rights</h2>
    <p>You may request a copy of your data, correct it, or ask us to delete it at any time by reaching out via our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a>.</p>

    <h2>8. Changes to This Policy</h2>
    <p>We may update this policy from time to time. Material changes will be announced on this page with an updated revision date.</p>
  </main>
@endsection
