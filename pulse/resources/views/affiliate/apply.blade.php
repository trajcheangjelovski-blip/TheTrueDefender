@extends('layouts.app')
@section('title', 'Become an Affiliate — TheTrueDefender')
@section('meta_description', 'Earn money by sharing TheTrueDefender. Get paid for the readers you bring and a commission on every shop sale you refer.')

@section('content')
  <main class="page-main">
    <h1>Become an Affiliate</h1>
    <p class="page-sub">Share our stories and products with your audience — get paid for every reader you bring and earn a commission on every sale.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:24px 0">
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px">
        <div style="font-size:1.4rem">🔗</div>
        <strong>1. Get your link</strong>
        <p style="font-size:.85rem;opacity:.75;margin-top:6px">Apply below. Once approved, you get a personal referral link that works on every article and product.</p>
      </div>
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px">
        <div style="font-size:1.4rem">📣</div>
        <strong>2. Share it</strong>
        <p style="font-size:.85rem;opacity:.75;margin-top:6px">Post it on your site, social channels, or newsletter. Every unique visitor is tracked to your account.</p>
      </div>
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px">
        <div style="font-size:1.4rem">💵</div>
        <strong>3. Get paid</strong>
        <p style="font-size:.85rem;opacity:.75;margin-top:6px">Earn a fixed rate per 1,000 valid visits, plus a commission on shop orders your visitors place.</p>
      </div>
    </div>

    @if(session('status'))
      <p style="color:#10b981;font-weight:600;margin-bottom:20px">{{ session('status') }}</p>
    @endif

    <form class="contact-form" method="POST" action="{{ route('affiliate.apply.submit') }}">
      @csrf
      <div>
        <label for="aName">Your Name</label>
        <input type="text" id="aName" name="name" value="{{ old('name') }}" placeholder="John Smith" required />
        @error('name')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="aEmail">Email Address</label>
        <input type="email" id="aEmail" name="email" value="{{ old('email') }}" placeholder="you@email.com" required />
        @error('email')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="aPassword">Password</label>
        <input type="password" id="aPassword" name="password" placeholder="Minimum 8 characters" required />
        @error('password')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="aPassword2">Confirm Password</label>
        <input type="password" id="aPassword2" name="password_confirmation" placeholder="Repeat your password" required />
      </div>
      <div>
        <label for="aWebsite">Where will you promote us? (optional)</label>
        <input type="text" id="aWebsite" name="website" value="{{ old('website') }}" placeholder="Your website, YouTube, X/Facebook page…" />
      </div>
      <div>
        <label for="aNotes">Tell us about your audience (optional)</label>
        <textarea id="aNotes" name="notes" rows="4" placeholder="Audience size, topics, how you plan to share our content…">{{ old('notes') }}</textarea>
      </div>
      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;font-size:.85rem;line-height:1.55">
        <strong>Affiliate terms (the short version):</strong>
        <ul style="margin:8px 0 0 18px;opacity:.8">
          <li>You are paid for <strong>genuine visitors</strong> you refer — never for ad clicks.</li>
          <li><strong>Never click ads on our site and never ask anyone to click ads.</strong> This is grounds for immediate termination and forfeits unpaid balances.</li>
          <li>No bots, paid-to-click services, traffic exchanges, spam, or misleading promotion.</li>
          <li>Only unique human visits count — duplicates and automated traffic are filtered.</li>
          <li>Payouts are reviewed and approved manually. Rates may be adjusted with notice.</li>
        </ul>
        <label style="display:flex;gap:8px;align-items:center;margin-top:12px;cursor:pointer">
          <input type="checkbox" name="terms" value="1" style="width:auto" {{ old('terms') ? 'checked' : '' }} required />
          <span>I agree to the affiliate terms above.</span>
        </label>
        @error('terms')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <button type="submit">Submit Application</button>
    </form>

    <p style="margin-top:18px;font-size:.9rem;opacity:.7">
      Already approved? <a href="{{ route('affiliate.login') }}" style="color:var(--accent, #e33b4e)">Sign in to your dashboard</a>
    </p>
  </main>
@endsection
