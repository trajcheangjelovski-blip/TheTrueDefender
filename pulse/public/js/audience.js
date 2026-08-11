// ═══════════════════════════════════════════════════
// THE TRUE DEFENDER — audience.js
// Cookie consent, email subscribe forms, web-push opt-in, and the smart
// push soft-prompt bar. Analytics + cross-prompt coordination + the funnel
// live in engage.js (window.ttd); this file owns the push TRANSPORT and UI.
// ═══════════════════════════════════════════════════

(function () {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const LS = window.localStorage;
  const track = (n, p) => { try { window.ttd?.track(n, p); } catch (_) {} };

  // iOS only allows web push for sites SAVED TO THE HOME SCREEN (installed as a
  // web app) on iOS 16.4+. In a normal Safari tab, PushManager doesn't exist and
  // there is no way to subscribe — so we detect it and guide the user instead.
  const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  function showIosHint() {
    const h = document.getElementById('iosPushHint');
    if (h) h.hidden = false;
  }

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body || {}),
    });
  }

  const markSubscribed = () => { LS.setItem('ttd_subscribed', '1'); LS.setItem('dp_subscribed', '1'); };
  const isSubscribed = () => LS.getItem('ttd_subscribed') === '1' || LS.getItem('dp_subscribed') === '1';

  // ── Email subscribe forms (footer / popup / inline / Morning Brief) ──
  function wireSubscribeForms() {
    document.querySelectorAll('form[data-subscribe]').forEach(form => {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const email = form.querySelector('input[type="email"]')?.value;
        const source = form.dataset.source || 'footer';
        const btn = form.querySelector('button');
        const label = btn ? btn.textContent : '';

        try {
          // Include any followed topics so email interest is captured too.
          const topics = (() => { try { return window.ttd?.topics.realSlugs() || []; } catch (_) { return []; } })();
          const res = await postJSON('/subscribe', { email, source, topics });
          if (!res.ok) throw new Error();
          markSubscribed();
          track('newsletter_signup_success', { cta_location: form.dataset.ctaLocation || source });
          showSubscribeSuccess(form);
        } catch (_) {
          if (btn) { btn.textContent = 'Try again'; setTimeout(() => { btn.textContent = label; }, 3000); }
        }
      });
    });
  }

  // Section 18: a useful next step after signup (drives another pageview),
  // instead of a dead "Subscribed." Replaces the form with a compact success card.
  function showSubscribeSuccess(form) {
    const wrap = form.closest('[data-cta-location], .mb-full, .mb-compact, .mb-inline, .newsletter-card, .sub-popup-card, .article-subscribe, .nl-form')?.parentElement || form.parentElement;
    const card = document.createElement('div');
    card.className = 'sub-success';
    card.setAttribute('role', 'status');
    card.innerHTML =
      '<div class="sub-success-head"><span class="sub-success-flag">🇺🇸</span><strong>You’re in.</strong></div>' +
      '<p>The Defender Morning Brief is headed your way. While you’re here:</p>' +
      '<div class="sub-success-links">' +
        '<a href="/quiz" data-ss="quiz">🧠 Take today’s quiz</a>' +
        '<a href="/#categorySections" data-ss="read">📈 See what’s most read</a>' +
      '</div>';
    // Swap the form out for the success card (keep surrounding copy).
    form.style.display = 'none';
    form.insertAdjacentElement('afterend', card);
    card.querySelectorAll('[data-ss]').forEach(a =>
      a.addEventListener('click', () => track('subscribe_success_cta_click', { target: a.dataset.ss })));
  }

  // ── Web push ──
  function urlB64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
  }

  async function enablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      // Most common on iPhone Safari tabs — tell them how to enable it.
      if (isIos() && !isStandalone()) showIosHint();
      return false;
    }
    try {
      // Ask permission FIRST, while the click still counts as a user gesture —
      // registering the SW first can consume the gesture and silently block the prompt.
      track('push_browser_prompt');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') { track('push_permission_denied', { result: permission }); return false; }
      track('push_permission_granted');

      await navigator.serviceWorker.register('/sw.js');
      const reg = await navigator.serviceWorker.ready; // wait for the active worker

      const keyRes = await fetch('/push/key').then(r => r.json());
      if (!keyRes.key) return false;

      // Reuse an existing subscription if the browser already has one.
      const sub = await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlB64ToUint8Array(keyRes.key),
      });

      const json = sub.toJSON();
      await postJSON('/push/subscribe', {
        endpoint: sub.endpoint,
        keys: json.keys,
        contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
      });
      LS.setItem('dp_push', 'on');
      // A fresh endpoint now exists — push any topics the reader already follows.
      try { window.ttd?.syncPushTopics?.(); } catch (_) {}
      return true;
    } catch (e) {
      console.warn('Push enable failed', e);
      return false;
    }
  }
  window.dpEnablePush = enablePush;

  // ── Cookie consent banner ──
  function initConsent() {
    const banner = document.getElementById('cookieBanner');
    if (!banner) return;
    if (!LS.getItem('dp_consent')) banner.classList.add('show');

    banner.querySelector('[data-accept]')?.addEventListener('click', async () => {
      LS.setItem('dp_consent', 'accepted');
      banner.classList.remove('show');
      // Tie notifications to consent, as requested — needs this user gesture.
      await enablePush();
    });
    banner.querySelector('[data-decline]')?.addEventListener('click', () => {
      LS.setItem('dp_consent', 'declined');
      banner.classList.remove('show');
    });
  }

  // ── Subscription popup ──
  function initPopup() {
    const popup = document.getElementById('subPopup');
    if (!popup) return;

    const open = () => {
      popup.classList.add('show');
      window.ttd?.prompts.markShown('subPopup');
      if (isIos() && !isStandalone()) showIosHint(); // guide iPhone users up front
    };
    // Store the dismissal time so it can expire (see notYetPrompted below).
    const close = () => { popup.classList.remove('show'); LS.setItem('dp_popup', String(Date.now())); };

    // Close handlers + form + push — always wired, so the popup works whenever opened.
    popup.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
    popup.addEventListener('click', e => { if (e.target === popup) close(); });

    popup.querySelector('form[data-subscribe]')?.addEventListener('submit', () => {
      markSubscribed();
      setTimeout(() => popup.classList.remove('show'), 1600);
    });

    popup.querySelector('[data-enable-push]')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const ok = await enablePush();
      e.target.textContent = ok ? '✓ Notifications on' : 'Not enabled';
    });

    // On-demand ONLY: the popup opens when the reader clicks the header
    // "Subscribe" button. It no longer auto-appears (no timer, no exit-intent) —
    // the smart notification bar handles proactive opt-in now.
    document.querySelectorAll('.btn-subscribe, [data-open-subscribe]').forEach(b =>
      b.addEventListener('click', e => { e.preventDefault(); open(); }));
  }

  // ── Smart notification opt-in bar ──
  // A custom, two-step ask: this soft prompt shows first; only when the reader
  // clicks the positive CTA do we fire the REAL browser permission request. It
  // fires only after genuine engagement (2nd article, scroll depth, dwell, or a
  // return visit), is contextual to what the reader reads, and is platform-correct.
  function initPushBar() {
    const bar = document.getElementById('pushBar');
    if (!bar) return;

    const yesBtn = bar.querySelector('[data-push-yes]');
    const noBtn = bar.querySelector('[data-push-no]');
    const note = document.getElementById('pushBarNote');
    const iosHint = document.getElementById('pushBarIos');
    const setNote = (html) => { if (note) { note.innerHTML = html; note.hidden = false; } };
    const clearNote = () => { if (note) { note.hidden = true; note.innerHTML = ''; } };

    const hide = () => { bar.classList.remove('show'); setTimeout(() => { bar.hidden = true; }, 350); };

    // iPhone Safari can't subscribe to push from a tab — Apple requires the site
    // to be Added to the Home Screen first. So on iOS we HIDE the (useless) push
    // button and turn the bar into a clean install card with the steps.
    const iosInstall = isIos() && !isStandalone();
    if (iosInstall) {
      bar.classList.add('push-bar--ios');
      if (yesBtn) yesBtn.hidden = true;
      if (iosHint) iosHint.hidden = false;
      const t = bar.querySelector('[data-pb-title]');
      const s = bar.querySelector('[data-pb-sub]');
      const ic = bar.querySelector('[data-pb-icon]');
      if (t) t.textContent = 'Add TheTrueDefender to your iPhone';
      if (s) s.textContent = 'Apple only lets saved apps send alerts. It takes about 10 seconds — just follow all 4 steps below, in order, to the end:';
      if (ic) ic.textContent = '📱';
      if (noBtn) noBtn.textContent = 'Got it';
    }

    // Contextual copy: if the reader keeps reading one topic, ask about THAT.
    // (Backend delivers global breaking alerts today; the followed topic is
    //  stored so per-topic delivery can be switched on later without a rewrite.)
    let followTopic = null;
    if (!iosInstall) {
      const top = (window.ttd?.funnel.topTopics(1) || [])[0];
      if (top && top.count >= 2) {
        followTopic = top;
        const t = bar.querySelector('[data-pb-title]');
        const s = bar.querySelector('[data-pb-sub]');
        if (t) t.textContent = `Following ${top.name} news?`;
        if (s) s.textContent = `Get an alert when a major ${top.name} story breaks — not every time we publish.`;
        if (yesBtn) yesBtn.textContent = `🔔 Follow ${top.name} Alerts`;
      }
    }

    // Handlers are wired UNCONDITIONALLY so the button can never look "frozen".
    let busy = false;
    yesBtn?.addEventListener('click', async () => {
      if (busy) return;
      track('push_soft_prompt_accept', { topic: followTopic?.slug || null });

      // iPhone (not installed): push is impossible until the site is on the Home
      // Screen — show the install steps instead of a prompt that can't fire.
      if (isIos() && !isStandalone()) { if (iosHint) iosHint.hidden = false; return; }

      if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        setNote('Your browser doesn’t support alerts here — try Chrome, Edge, or Firefox.');
        return;
      }
      // Already blocked at the browser level: a prompt can't be shown, so guide them.
      if (Notification.permission === 'denied') {
        setNote('🔔 Alerts are blocked for this site. Click the icon on the left of the address bar → allow <strong>Notifications</strong>, then reload.');
        return;
      }

      busy = true;
      yesBtn.textContent = 'Enabling…';
      setNote('👉 Choose <strong>Allow</strong> on the prompt. Don’t see it? Click the 🔔 / lock icon in your browser’s address bar.');

      let ok = false;
      try { ok = await enablePush(); } catch (_) {}
      busy = false;

      if (ok) {
        // Remember the topic the reader opted in "for" (future per-topic delivery).
        if (followTopic) { try { window.ttd?.topics.follow(followTopic.slug, followTopic.name, { alerts: true }); } catch (_) {} }
        showPushSuccess(bar);
        LS.setItem('dp_push', 'on');
      } else if (Notification.permission === 'denied') {
        yesBtn.textContent = followTopic ? `🔔 Follow ${followTopic.name} Alerts` : 'Send Me Breaking Alerts';
        setNote('🔔 Alerts got blocked. Click the icon on the left of the address bar → allow <strong>Notifications</strong>, then reload.');
      } else if (Notification.permission === 'granted') {
        yesBtn.textContent = 'Try again';
        setNote('Almost there — tap <strong>Try again</strong> to finish turning on alerts.');
      } else {
        yesBtn.textContent = followTopic ? `🔔 Follow ${followTopic.name} Alerts` : 'Send Me Breaking Alerts';
        setNote('Tap the button again, then choose <strong>Allow</strong> on the prompt to finish.');
      }
    });

    noBtn?.addEventListener('click', () => {
      track('push_soft_prompt_dismiss');
      LS.setItem('dp_pushbar', String(Date.now())); // suppress for 7 days
      window.ttd?.prompts.dismiss('pushBar', 7);
      hide();
    });

    // Section 19: push success state — confirm + let them (future) pick topics.
    function showPushSuccess(barEl) {
      const inner = barEl.querySelector('.push-bar-inner');
      clearNote();
      if (inner) {
        inner.innerHTML =
          '<span class="push-bar-icon">🔔</span>' +
          '<div class="push-bar-text"><strong>You’re subscribed to breaking alerts.</strong>' +
          '<span>We’ll only notify you when something important actually happens.</span></div>' +
          '<button class="push-bar-no" type="button" data-close-success>Done</button>';
        inner.querySelector('[data-close-success]')?.addEventListener('click', hide);
      }
      setTimeout(hide, 6000);
    }

    // ── Eligibility ──
    const SUPPRESS_MS = 7 * 24 * 60 * 60 * 1000;
    const dismissedRecently = () => {
      const t = parseInt(LS.getItem('dp_pushbar') || '0', 10);
      return t && (Date.now() - t) < SUPPRESS_MS;
    };
    const eligible = () => {
      if (LS.getItem('dp_push') === 'on') return false;                 // already subscribed
      if ('Notification' in window && Notification.permission === 'granted') return false;
      if (dismissedRecently()) return false;
      // A hard block can't be re-prompted (except on iOS, where we guide to install).
      if (!isIos() && 'Notification' in window && Notification.permission === 'denied') return false;
      return true;
    };

    let shown = false;
    const reveal = () => {
      if (shown || !eligible()) return;
      // Centralized coordination: never stack on another interruptive prompt.
      if (window.ttd && !window.ttd.prompts.canShow('pushBar', 7)) return;
      shown = true;
      window.removeEventListener('scroll', onScroll);
      window.ttd?.prompts.markShown('pushBar');
      bar.hidden = false;
      requestAnimationFrame(() => bar.classList.add('show'));
      track('push_soft_prompt_view', { context: reveal._ctx || 'engagement', topic: followTopic?.slug || null });
    };
    // Exposed so engage.js (2nd article) and inline events (poll/quiz/comment/
    // topic-follow) can trigger the soft ask at high-intent moments.
    window.dpRevealPush = (ctx) => { reveal._ctx = ctx || 'engagement'; reveal(); };

    if (!eligible()) return;

    // Trigger 1: scrolled halfway = genuine engagement.
    const onScroll = () => {
      const reached = (window.scrollY + window.innerHeight) / (document.body.scrollHeight || 1);
      if (reached >= 0.5) { reveal._ctx = 'scroll_depth'; reveal(); }
    };
    window.addEventListener('scroll', onScroll, { passive: true });

    // Trigger 2: return visitor → sooner. Trigger 3: dwell fallback.
    const visits = parseInt(LS.getItem('ttd_visits') || LS.getItem('dp_visits') || '1', 10) || 1;
    if (visits >= 2) setTimeout(() => { reveal._ctx = 'return_visit'; reveal(); }, 8000);
    setTimeout(() => { reveal._ctx = 'dwell'; reveal(); }, 35000);
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireSubscribeForms();
    initConsent();
    initPopup();
    initPushBar();
  });
})();
