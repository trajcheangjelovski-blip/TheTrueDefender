@extends('layouts.app')
@section('title', 'Affiliate Login — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:480px">
    <h1>Affiliate Login</h1>
    <p class="page-sub">Sign in to see your clicks, earnings, and payouts.</p>

    @if(session('status'))
      <p style="color:#10b981;font-weight:600;margin-bottom:20px">{{ session('status') }}</p>
    @endif

    <form class="contact-form" method="POST" action="{{ route('affiliate.login.submit') }}">
      @csrf
      <div>
        <label for="lEmail">Email Address</label>
        <input type="email" id="lEmail" name="email" value="{{ old('email') }}" placeholder="you@email.com" required autofocus />
        @error('email')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="lPassword">Password</label>
        <input type="password" id="lPassword" name="password" required />
      </div>
      <button type="submit">Sign In</button>
    </form>

    <p style="margin-top:18px;font-size:.9rem;opacity:.7">
      Not an affiliate yet? <a href="{{ route('affiliate.apply') }}" style="color:var(--accent, #e33b4e)">Apply here</a>
    </p>
  </main>
@endsection
