@extends('layouts.app')
@section('title', 'Terms of Service — TheTrueDefender')

@section('content')
  <main class="page-main">
    <h1>Terms of Service</h1>
    <p class="page-sub">Last updated: {{ date('F j, Y') }}</p>
    <p><em>This is placeholder text. Replace it with your reviewed terms before launch.</em></p>

    <h2>1. Acceptance of Terms</h2>
    <p>By accessing TheTrueDefender website, you agree to be bound by these Terms of Service. If you do not agree, please do not use the site.</p>

    <h2>2. Use of Content</h2>
    <p>All articles, images, and other content on this site are the property of TheTrueDefender unless otherwise noted. You may share links to our content freely; republishing full articles requires written permission.</p>

    <h2>3. Shop Purchases</h2>
    <p>Product listings, prices, and availability in the Patriot Shop are subject to change without notice. Orders are subject to our shipping and return policies, provided at checkout.</p>

    <h2>4. User Conduct</h2>
    <p>You agree not to misuse the site, including attempting to gain unauthorized access, scraping content at scale, or submitting unlawful material through our forms.</p>

    <h2>5. Disclaimer</h2>
    <p>Content is provided "as is" for general information. Opinion pieces reflect the views of their authors, not necessarily those of TheTrueDefender.</p>

    <h2>6. Limitation of Liability</h2>
    <p>To the maximum extent permitted by law, TheTrueDefender shall not be liable for any indirect or consequential damages arising from your use of the site.</p>

    <h2>7. Contact</h2>
    <p>Questions about these terms? Reach us through our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a>.</p>
  </main>
@endsection
