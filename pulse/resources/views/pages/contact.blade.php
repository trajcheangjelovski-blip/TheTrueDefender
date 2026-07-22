@extends('layouts.app')
@section('title', 'Contact — TheTrueDefender')

@section('content')
  <main class="page-main">
    <h1>Contact Us</h1>
    <p class="page-sub">News tips, corrections, shop questions, or feedback — we read every message.</p>

    <div class="contact-emails">
      <p><strong>Newsroom &amp; tips:</strong> <a href="mailto:news@thetruedefender.news">news@thetruedefender.news</a></p>
      <p><strong>Corrections:</strong> <a href="mailto:corrections@thetruedefender.news">corrections@thetruedefender.news</a> — see our <a href="{{ route('page', 'corrections') }}">Corrections policy</a></p>
      <p><strong>Support &amp; shop orders:</strong> <a href="mailto:support@thetruedefender.news">support@thetruedefender.news</a></p>
      <p style="color:var(--text-dim);font-size:.9rem">TheTrueDefender is an independent news publication. Learn how we work on our <a href="{{ route('page', 'editorial-standards') }}">Editorial Standards</a> page.</p>
    </div>

    @if(session('status'))
      <p style="color:#10b981;font-weight:600;margin-bottom:20px">{{ session('status') }}</p>
    @endif

    <form class="contact-form" method="POST" action="{{ route('contact.submit') }}">
      @csrf
      <div>
        <label for="cName">Your Name</label>
        <input type="text" id="cName" name="name" value="{{ old('name') }}" placeholder="John Smith" required />
        @error('name')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="cEmail">Email Address</label>
        <input type="email" id="cEmail" name="email" value="{{ old('email') }}" placeholder="you@email.com" required />
        @error('email')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="cSubject">Subject</label>
        <input type="text" id="cSubject" name="subject" value="{{ old('subject') }}" placeholder="What is this about?" required />
      </div>
      <div>
        <label for="cMessage">Message</label>
        <textarea id="cMessage" name="message" rows="6" placeholder="Write your message…" required>{{ old('message') }}</textarea>
        @error('message')<span style="color:var(--accent-2);font-size:.8rem">{{ $message }}</span>@enderror
      </div>
      <button type="submit">Send Message</button>
    </form>
  </main>
@endsection
