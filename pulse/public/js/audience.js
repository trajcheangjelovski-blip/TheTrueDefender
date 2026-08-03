// ═══════════════════════════════════════════════════
// THE DAILY PULSE — audience.js
// Cookie consent, email subscribe forms, web-push opt-in, popup.
// ═══════════════════════════════════════════════════

(function () {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const LS = window.localStorage;

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

  // ── Email subscribe forms (footer / popup / inline) ──
  function wireSubscribeForms() {
    document.querySelectorAll('form[data-subscribe]').forEach(form => {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const email = form.querySelector('input[type="email"]')?.value;
        const source = form.dataset.source || 'footer';
        const btn = form.querySelector('button');
        const label = btn ? btn.textContent : '';

        // Subscribing also opts the reader into browser notifications. Fire this
        // first, while the click still counts as a user gesture (required for the
        // permission prompt) — before the awaited network call below.
        enablePush();

        try {
          const res = await postJSON('/subscribe', { email, source });
          if (!res.ok) throw new Error();
          if (btn) { btn.textContent = '✓ Subscribed!'; btn.style.background = 'linear-gradient(135deg,#10b981,#047857)'; }
          form.querySelector('input[type="email"]').value = '';
        } catch (_) {
          if (btn) btn.textContent = 'Try again';
        }
        if (btn) setTimeout(() => { btn.textContent = label; btn.style.background = ''; }, 3000);
      });
    });
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
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') return false;

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
      if (isIos() && !isStandalone()) showIosHint(); // guide iPhone users up front
    };
    // Store the dismissal time so it can expire (see notYetPrompted below).
    const close = () => { popup.classList.remove('show'); LS.setItem('dp_popup', String(Date.now())); };

    // Close handlers + form + push — always wired, so the popup works whenever opened.
    popup.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
    popup.addEventListener('click', e => { if (e.target === popup) close(); });

    popup.querySelector('form[data-subscribe]')?.addEventListener('submit', () => {
      LS.setItem('dp_subscribed', '1');
      setTimeout(() => popup.classList.remove('show'), 1400);
    });

    popup.querySelector('[data-enable-push]')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const ok = await enablePush();
      e.target.textContent = ok ? '✓ Notifications on' : 'Not enabled';
    });

    // The header "Subscribe" button opens the popup on demand — always available.
    document.querySelectorAll('.btn-subscribe, [data-open-subscribe]').forEach(b =>
      b.addEventListener('click', e => { e.preventDefault(); open(); }));

    // Show again if never subscribed and it's been over 7 days since last dismissal
    // (so a single "No thanks" doesn't silence it forever).
    const SUPPRESS_MS = 7 * 24 * 60 * 60 * 1000;
    const notYetPrompted = () => {
      if (LS.getItem('dp_subscribed')) return false;
      const t = parseInt(LS.getItem('dp_popup') || '0', 10);
      return ! (t && (Date.now() - t) < SUPPRESS_MS);
    };

    // Desktop exit-intent: open when the cursor leaves toward the tab bar/close.
    // Non-intrusive — it never interrupts reading, only fires on the way out.
    if (! ('ontouchstart' in window) && window.innerWidth >= 768) {
      const onExit = e => {
        if (e.clientY <= 0 && notYetPrompted()) {
          open();
          document.removeEventListener('mouseout', onExit);
        }
      };
      document.addEventListener('mouseout', onExit);
    }

    // Auto-open once, but never stacked on the cookie banner: wait until the
    // visitor has dealt with cookies (dp_consent set), then a calm delay.
    if (notYetPrompted()) {
      const tryOpen = () => {
        if (! notYetPrompted()) return;
        if (LS.getItem('dp_consent')) setTimeout(open, 4000); // 4s after cookies handled
        else setTimeout(tryOpen, 3000);                       // keep waiting on the banner
      };
      setTimeout(tryOpen, 8000);
    }
  }

  // ── Smart notification opt-in bar ──
  // Fires only after the reader ENGAGES (scroll depth, dwell, or a return visit),
  // asks softly first, and is platform-correct. This maximizes real opt-ins:
  // a cold prompt that gets "Block"ed is lost forever, so we never prompt cold.
  function initPushBar() {
    const bar = document.getElementById('pushBar');
    if (!bar) return;

    // Count visits (a return visitor is the best opt-in audience).
    const visits = (parseInt(LS.getItem('dp_visits') || '0', 10) || 0) + 1;
    LS.setItem('dp_visits', String(visits));

    const SUPPRESS_MS = 7 * 24 * 60 * 60 * 1000;
    const dismissedRecently = () => {
      const t = parseInt(LS.getItem('dp_pushbar') || '0', 10);
      return t && (Date.now() - t) < SUPPRESS_MS;
    };

    function eligible() {
      if (LS.getItem('dp_push') === 'on') return false;                 // already subscribed
      if ('Notification' in window && Notification.permission === 'granted') return false;
      if (dismissedRecently()) return false;
      // A hard block can't be re-prompted (except on iOS, where we guide to install).
      if (!isIos() && 'Notification' in window && Notification.permission === 'denied') return false;
      return true;
    }

    if (!eligible()) return;

    let shown = false;
    const hide = () => { bar.classList.remove('show'); setTimeout(() => { bar.hidden = true; }, 350); };
    const reveal = () => {
      if (shown || !eligible()) return;
      // Never stack on the cookie banner or the email popup.
      const cb = document.getElementById('cookieBanner');
      const sp = document.getElementById('subPopup');
      if (cb && cb.classList.contains('show')) return;
      if (sp && sp.classList.contains('show')) return;
      shown = true;
      window.removeEventListener('scroll', onScroll);
      bar.hidden = false;
      requestAnimationFrame(() => bar.classList.add('show'));
    };

    // Trigger 1: scrolled halfway through the page = genuine engagement.
    const onScroll = () => {
      const reached = (window.scrollY + window.innerHeight) / (document.body.scrollHeight || 1);
      if (reached >= 0.5) reveal();
    };
    window.addEventListener('scroll', onScroll, { passive: true });

    // Trigger 2: return visitor → prompt sooner. Trigger 3: dwell fallback.
    if (visits >= 2) setTimeout(reveal, 6000);
    setTimeout(reveal, 30000);

    bar.querySelector('[data-push-yes]')?.addEventListener('click', async () => {
      // iPhone (not installed): push is impossible until the site is on the Home
      // Screen — so show the install steps instead of a prompt that can't work.
      if (isIos() && !isStandalone()) {
        const hint = document.getElementById('pushBarIos');
        if (hint) hint.hidden = false;
        return;
      }
      const yes = bar.querySelector('[data-push-yes]');
      yes.disabled = true;
      const ok = await enablePush();
      if (ok) { yes.textContent = '✓ You’re in!'; setTimeout(hide, 1400); }
      else { yes.textContent = 'Not enabled'; yes.disabled = false; }
    });

    bar.querySelector('[data-push-no]')?.addEventListener('click', () => {
      LS.setItem('dp_pushbar', String(Date.now())); // suppress for 7 days
      hide();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireSubscribeForms();
    initConsent();
    initPopup();
    initPushBar();
  });
})();
