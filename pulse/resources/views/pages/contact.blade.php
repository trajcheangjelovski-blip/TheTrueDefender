@extends('layouts.app')
@section('title', 'Contact — TheTrueDefender')

@section('content')
  <main class="page-main">
    <h1>Contact Us</h1>
    <p class="page-sub">News tips, corrections, shop questions, or feedback — we read every message.</p>

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
