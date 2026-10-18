// ═════════════════════════════════════════════════
// consent.js: consent-aware loading of optional analytics and advertising.
//
// The consent MESSAGE itself comes from Google's Privacy & messaging (Funding Choices), a CMP certified
// by Google; this file does not draw a banner and does not store a choice of its own. It only reads the
// result and decides what may load.
//
// window.TTD_CONSENT (set in the page head): { required: bool, ga: 'G-…'|'', ads: 'ca-pub-…'|'' }
//   required = the visitor is in a region where prior consent is needed (EEA/UK/Switzerland) or the
//              region is unknown. Unknown is treated as required.
//
// Rules:
//   - Not required: analytics loads straight away; advertising loads when the browser is idle.
//   - Required: nothing optional loads or is stored until the CMP reports consent through the IAB TCF API
//     (analytics needs purposes 1 and 8; the ad script waits until the CMP has loaded and Google's own
//     code applies the visitor's choices). Closing, scrolling or ignoring a message is not consent.
//   - Withdrawal: when consent is withdrawn the first-party visitor id is deleted and analytics stops.
// ═════════════════════════════════════════════════
(function () {
  'use strict';

  var cfg = window.TTD_CONSENT || { required: true, ga: '', ads: '' };
  var required = cfg.required !== false;

  var analyticsOn = !required;
  var adsReleased = !required;
  var analyticsCbs = [];
  var gaLoaded = false;
  var adsLoaded = false;

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = window.gtag || gtag;

  // ── Analytics ──
  function loadGa() {
    if (gaLoaded || !cfg.ga) return;
    gaLoaded = true;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(cfg.ga);
    document.head.appendChild(s);
    window.gtag('js', new Date());
    window.gtag('config', cfg.ga);
  }

  function setAnalytics(on) {
    on = !!on;
    var changed = on !== analyticsOn;
    analyticsOn = on;
    if (on) {
      window.gtag('consent', 'update', { analytics_storage: 'granted' });
      loadGa();
      analyticsCbs.splice(0).forEach(function (fn) { try { fn(); } catch (_) {} });
    } else if (changed) {
      window.gtag('consent', 'update', { analytics_storage: 'denied' });
      try { window.localStorage.removeItem('ttd_vid'); } catch (_) {}
    }
  }

  // ── Advertising ──
  function loadAds() {
    if (adsLoaded || !cfg.ads) return;
    adsLoaded = true;
    var ad = document.createElement('script');
    ad.async = true;
    ad.crossOrigin = 'anonymous';
    ad.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent(cfg.ads);
    document.head.appendChild(ad);
  }

  function scheduleAds() {
    if (!adsReleased) return;
    var fired = false;
    function go() { if (fired) return; fired = true; loadAds(); }
    if ('requestIdleCallback' in window) { window.requestIdleCallback(go, { timeout: 3000 }); } else { setTimeout(go, 2500); }
    ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (e) {
      window.addEventListener(e, go, { once: true, passive: true });
    });
  }

  // ── TCF (only meaningful when the CMP is present; required regions) ──
  function applyTcData(tc, ok) {
    if (!ok || !tc) return;
    var status = tc.eventStatus;
    if (status !== 'tcloaded' && status !== 'useractioncomplete') return;
    var p = (tc.purpose && tc.purpose.consents) || {};
    setAnalytics(!!(p[1] && p[8]));
    if (!adsReleased) { adsReleased = true; scheduleAds(); }   // Google's ad code applies the stored choices itself
  }

  function listenTcf() {
    var tries = 0;
    (function wait() {
      if (typeof window.__tcfapi === 'function') {
        window.__tcfapi('addEventListener', 2, applyTcData);
        return;
      }
      if (++tries < 40) setTimeout(wait, 500);   // CMP not loaded (yet); nothing optional loads meanwhile
    })();
  }

  // ── Reopen privacy choices ──
  function openChoices() {
    var opened = false;
    window.googlefc = window.googlefc || {};
    window.googlefc.callbackQueue = window.googlefc.callbackQueue || [];
    window.googlefc.callbackQueue.push({
      CONSENT_API_READY: function () {
        try {
          if (window.googlefc && typeof window.googlefc.showRevocationMessage === 'function') {
            window.googlefc.showRevocationMessage();
            opened = true;
          }
        } catch (_) {}
      },
    });
    return new Promise(function (resolve) { setTimeout(function () { resolve(opened); }, 1500); });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-privacy-choices]') : null;
    if (!a) return;
    e.preventDefault();
    openChoices().then(function (opened) {
      // CMP unavailable (not configured or blocked): fall back to the written choices on the policy page.
      if (!opened) window.location.href = a.getAttribute('href') || '/privacy#choices';
    });
  });

  window.ttdConsent = {
    required: required,
    analytics: function () { return analyticsOn; },
    // Runs fn once analytics is permitted (immediately when it already is).
    onAnalytics: function (fn) { if (analyticsOn) { try { fn(); } catch (_) {} } else { analyticsCbs.push(fn); } },
    openChoices: openChoices,
    _apply: applyTcData,   // exposed for tests
  };

  // ── Start ──
  if (required) {
    window.gtag('consent', 'default', { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    listenTcf();
  } else {
    loadGa();
    scheduleAds();
  }
})();
