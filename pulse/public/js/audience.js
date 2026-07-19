// ═══════════════════════════════════════════════════
// THE DAILY PULSE — audience.js
// Cookie consent, email subscribe forms, web-push opt-in, popup.
// ═══════════════════════════════════════════════════

(function () {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const LS = window.localStorage;

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
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
    try {
      const reg = await navigator.serviceWorker.register('/sw.js');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') return false;

      const keyRes = await fetch('/push/key').then(r => r.json());
      if (!keyRes.key) return false;

      const sub = await reg.pushManager.subscribe({
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

    const open = () => popup.classList.add('show');
    const close = () => { popup.classList.remove('show'); LS.setItem('dp_popup', 'dismissed'); };

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

    // Auto-open once after a delay, unless already subscribed or dismissed.
    if (!LS.getItem('dp_popup') && !LS.getItem('dp_subscribed')) {
      setTimeout(open, 9000);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireSubscribeForms();
    initConsent();
    initPopup();
  });
})();
