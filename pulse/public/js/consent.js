/* First-party preferences, not an advertising CMP. Never requests notification permission. */
(function () {
  'use strict';
  const read = () => {
    const value = document.cookie.split('; ').find(c => c.startsWith('ttd_privacy='))?.split('=')[1];
    const match = /^v2\.a([01])\.m([01])$/.exec(value || '');
    return match ? { analytics: match[1] === '1', marketing: match[2] === '1' } : null;
  };
  let state = read();
  let gaLoaded = false;
  const allowed = purpose => state?.[purpose] === true;
  const config = (() => { try { return JSON.parse(document.getElementById('ttdPrivacyConfig')?.textContent || '{}'); } catch (_) { return {}; } })();
  function analytics() {
    if (!allowed('analytics') || gaLoaded || !/^G-[A-Z0-9]+$/.test(config.gaId || '')) return;
    gaLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { if (allowed('analytics')) window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    window.gtag('js', new Date());
    window.gtag('config', config.gaId, { allow_google_signals: false, allow_ad_personalization_signals: false });
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.gaId);
    document.head.appendChild(script);
  }
  function eraseCookie(name) {
    const domains = [null, location.hostname, '.' + location.hostname];
    const labels = location.hostname.split('.');
    for (let i = 1; i < labels.length - 1; i++) domains.push('.' + labels.slice(i).join('.'));
    domains.forEach(domain => {
      document.cookie = name + '=; Max-Age=0; Path=/' + (domain ? '; Domain=' + domain : '') + '; SameSite=Lax';
    });
  }
  function save(next) {
    const previous = state;
    state = { analytics: next.analytics === true, marketing: next.marketing === true };
    document.cookie = 'ttd_privacy=v2.a' + Number(state.analytics) + '.m' + Number(state.marketing)
      + '; Max-Age=15552000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    try { localStorage.setItem('dp_consent', 'preferences-v2'); } catch (_) {}
    if (!state.analytics) {
      try {
        ['ttd_vid', 'ttd_topic_interest', 'ttd_last_visit_day', 'ttd_last_visit_ts', 'ttd_prev_visit_day', 'ttd_prev_visit_ts', 'ttd_visits'].forEach(key => localStorage.removeItem(key));
        sessionStorage.removeItem('ttd_session_articles');
      } catch (_) {}
      document.cookie.split(';').forEach(c => { const name = c.split('=')[0].trim(); if (/^(_ga|_gid|_gat)/.test(name)) eraseCookie(name); });
    }
    if (!state.marketing) eraseCookie('aff_ref');
    document.getElementById('cookieBanner')?.classList.remove('show');
    const banner = document.getElementById('cookieBanner');
    if (banner) banner.hidden = true;
    // Reload stops already-loaded optional scripts and clears server-side referral cookies.
    if (previous?.analytics && !state.analytics || previous?.marketing && !state.marketing) {
      location.reload();
      return;
    }
    analytics();
    window.dispatchEvent(new CustomEvent('ttd:consent', { detail: state }));
  }
  window.ttdConsent = { allows: allowed, hasChoice: () => state !== null };
  analytics();
  document.addEventListener('DOMContentLoaded', () => {
    const banner = document.getElementById('cookieBanner');
    if (!banner) return;
    const show = () => { banner.hidden = false; banner.classList.add('show'); };
    if (!state) show();
    const analyticsBox = document.getElementById('consentAnalytics');
    const marketingBox = document.getElementById('consentMarketing');
    const customize = () => {
      show();
      document.getElementById('cookieChoices').hidden = false;
      analyticsBox.checked = allowed('analytics'); marketingBox.checked = allowed('marketing');
      banner.querySelector('[data-consent-save]').hidden = false;
      banner.querySelector('[data-consent-customize]').setAttribute('aria-expanded', 'true');
      analyticsBox.focus();
    };
    banner.querySelector('[data-consent-customize]')?.addEventListener('click', customize);
    document.querySelectorAll('[data-cookie-settings]').forEach(button => button.addEventListener('click', customize));
    banner.querySelector('[data-consent-reject]')?.addEventListener('click', () => save({ analytics: false, marketing: false }));
    banner.querySelector('[data-consent-accept]')?.addEventListener('click', () => save({ analytics: true, marketing: true }));
    banner.querySelector('[data-consent-save]')?.addEventListener('click', () => save({ analytics: analyticsBox.checked, marketing: marketingBox.checked }));
  });
})();
