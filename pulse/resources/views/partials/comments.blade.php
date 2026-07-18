{{-- Reader comments: public shows name + surname + comment only.
     Email & phone are collected but stored privately (admin-only). --}}
<section class="section article-comments" id="comments" style="max-width:760px;margin:0 auto;padding:40px 20px">
  @php
    $isForum = $post->category?->slug === 'opinion';
    $total = $post->approvedComments->count() + $post->approvedComments->sum(fn ($c) => $c->approvedReplies->count());
  @endphp
  <div class="section-head">
    <h2><span class="head-accent">💬</span> {{ $isForum ? 'Discussion' : 'Reader Opinions' }}
      <span style="font-size:.7em;opacity:.5;font-weight:500">({{ $total }})</span>
    </h2>
    <div class="head-line"></div>
  </div>
  @if($isForum)
    <p style="opacity:.65;font-size:.9rem;margin:-6px 0 4px">This is an open discussion — reply to others or start a new comment below.</p>
  @endif

  {{-- Existing approved comments (top-level) with their replies --}}
  <div class="comment-list" style="display:flex;flex-direction:column;gap:14px;margin:20px 0">
    @forelse($post->approvedComments as $comment)
      <article style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px 18px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <span style="width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:linear-gradient(135deg,{{ $color }},#1a1030);color:#fff;font-size:.8rem">
            {{ strtoupper(mb_substr($comment->name,0,1) . mb_substr($comment->surname,0,1)) }}
          </span>
          <div>
            <strong style="display:block;font-size:.92rem">{{ $comment->display_name }}</strong>
            <span style="font-size:.75rem;opacity:.55">{{ $comment->created_at->diffForHumans() }}</span>
          </div>
        </div>
        <p style="font-size:.95rem;line-height:1.6;opacity:.9;white-space:pre-line">{{ $comment->body }}</p>
        <button type="button" class="reply-btn" data-id="{{ $comment->id }}" data-name="{{ $comment->display_name }}"
                style="margin-top:8px;background:none;border:none;color:var(--accent,#e33b4e);font-size:.8rem;font-weight:600;cursor:pointer;padding:0">↩ Reply</button>

        {{-- Replies --}}
        @foreach($comment->approvedReplies as $reply)
          <div style="margin:12px 0 0 26px;padding:12px 14px;border-left:2px solid {{ $color }}55;background:rgba(255,255,255,.03);border-radius:0 10px 10px 0">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span style="width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:linear-gradient(135deg,{{ $color }},#1a1030);color:#fff;font-size:.65rem">
                {{ strtoupper(mb_substr($reply->name,0,1) . mb_substr($reply->surname,0,1)) }}
              </span>
              <strong style="font-size:.85rem">{{ $reply->display_name }}</strong>
              <span style="font-size:.72rem;opacity:.5">{{ $reply->created_at->diffForHumans() }}</span>
            </div>
            <p style="font-size:.9rem;line-height:1.55;opacity:.88;white-space:pre-line;margin:0">{{ $reply->body }}</p>
            <button type="button" class="reply-btn" data-id="{{ $comment->id }}" data-name="{{ $reply->display_name }}"
                    style="margin-top:6px;background:none;border:none;color:var(--accent,#e33b4e);font-size:.76rem;font-weight:600;cursor:pointer;padding:0">↩ Reply</button>
          </div>
        @endforeach
      </article>
    @empty
      <p style="opacity:.6;font-size:.9rem">Be the first to share your opinion on this story.</p>
    @endforelse
  </div>

  {{-- Comment form --}}
  <h3 id="cf_heading" style="font-size:1.05rem;margin:28px 0 6px">Share your opinion</h3>

  @if(session('comment_status'))
    <p style="color:#10b981;font-weight:600;margin-bottom:16px">{{ session('comment_status') }}</p>
  @endif

  <div id="cf_replying" style="display:none;align-items:center;gap:10px;background:rgba(227,59,78,.08);border:1px solid rgba(227,59,78,.3);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.85rem">
    <span>Replying to <strong id="cf_replying_name"></strong></span>
    <button type="button" id="cf_cancel_reply" style="margin-left:auto;background:none;border:none;color:var(--accent,#e33b4e);font-weight:600;cursor:pointer">Cancel</button>
  </div>

  <form method="POST" action="{{ route('comment.store', $post->slug) }}" class="comment-form" id="cf_form" style="display:grid;gap:12px">
    @csrf

    {{-- Honeypot (hidden from humans) --}}
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true" />
    <input type="hidden" name="parent_id" id="cf_parent" value="{{ old('parent_id') }}" />

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <label for="cf_name" style="display:block;font-size:.8rem;opacity:.7;margin-bottom:4px">First name *</label>
        <input id="cf_name" type="text" name="name" value="{{ old('name') }}" required
               style="width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit" />
        @error('name')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="cf_surname" style="display:block;font-size:.8rem;opacity:.7;margin-bottom:4px">Surname *</label>
        <input id="cf_surname" type="text" name="surname" value="{{ old('surname') }}" required
               style="width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit" />
        @error('surname')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="cf_email" style="display:block;font-size:.8rem;opacity:.7;margin-bottom:4px">Email * <span style="opacity:.6">(kept private)</span></label>
        <input id="cf_email" type="email" name="email" value="{{ old('email') }}" required
               style="width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit" />
        @error('email')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="cf_phone" style="display:block;font-size:.8rem;opacity:.7;margin-bottom:4px">Phone * <span style="opacity:.6">(kept private)</span></label>
        <input id="cf_phone" type="tel" name="phone" value="{{ old('phone') }}" required
               style="width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit" />
        @error('phone')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror
      </div>
    </div>

    <div>
      <label for="cf_body" style="display:block;font-size:.8rem;opacity:.7;margin-bottom:4px">Your opinion *</label>
      <textarea id="cf_body" name="body" rows="4" required maxlength="3000"
                style="width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit">{{ old('body') }}</textarea>
      @error('body')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror
    </div>

    <label style="display:flex;gap:8px;align-items:flex-start;font-size:.82rem;opacity:.8;cursor:pointer">
      <input type="checkbox" name="consent" value="1" {{ old('consent') ? 'checked' : '' }} required style="width:auto;margin-top:3px" />
      <span>Only my first name and surname will be shown publicly. My email and phone are kept private by TheTrueDefender and not published. I agree to the <a href="{{ route('page', 'privacy') }}" style="color:var(--accent,#e33b4e)">privacy policy</a>.</span>
    </label>
    @error('consent')<span style="color:var(--accent-2);font-size:.78rem">{{ $message }}</span>@enderror

    <button type="submit" id="cf_submit" style="justify-self:start;background:var(--accent,#e33b4e);border:none;color:#fff;font-weight:700;padding:11px 22px;border-radius:8px;cursor:pointer">Post my opinion</button>
    <p style="font-size:.75rem;opacity:.5;margin:0">Comments are reviewed before they appear.</p>
  </form>

  <script>
    (function () {
      var parent = document.getElementById('cf_parent');
      var banner = document.getElementById('cf_replying');
      var nameEl = document.getElementById('cf_replying_name');
      var heading = document.getElementById('cf_heading');
      var submit = document.getElementById('cf_submit');
      var form = document.getElementById('cf_form');

      function setReply(id, name) {
        parent.value = id;
        nameEl.textContent = name;
        banner.style.display = 'flex';
        heading.textContent = 'Reply to ' + name;
        submit.textContent = 'Post reply';
        var pos = form.getBoundingClientRect().top + window.scrollY - 90;
        window.scrollTo({ top: pos, behavior: 'smooth' });
        document.getElementById('cf_body').focus();
      }
      function clearReply() {
        parent.value = '';
        banner.style.display = 'none';
        heading.textContent = 'Share your opinion';
        submit.textContent = 'Post my opinion';
      }

      document.querySelectorAll('.reply-btn').forEach(function (b) {
        b.addEventListener('click', function () {
          setReply(this.getAttribute('data-id'), this.getAttribute('data-name'));
        });
      });
      document.getElementById('cf_cancel_reply').addEventListener('click', clearReply);
    })();
  </script>
</section>
