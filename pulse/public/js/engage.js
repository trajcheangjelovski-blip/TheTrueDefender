// ═══════════════════════════════════════════════════════════════════
// THE TRUE DEFENDER — engage.js
// Retention funnel glue: first-party analytics, a centralized conversion
// PROMPT MANAGER (so a visitor is never bombarded), reading-funnel signals,
// topic following (localStorage-first, no account needed), and quiz streaks.
//
// This layers ON TOP of audience.js (which owns the push transport + the push
// opt-in bar). Nothing here talks to the network except analytics; all state
// is first-party localStorage/sessionStorage. Safe to load on every page.
// ═══════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  var LS = window.localStorage;
  var SS = window.sessionStorage;

  function readJSON(store, key, fallback) {
    try { return JSON.parse(store.getItem(key)) ?? fallback; } catch (_) { return fallback; }
  }
  function writeJSON(store, key, val) {
    try { store.setItem(key, JSON.stringify(val)); } catch (_) {}
  }
  function todayKey() {
    // Local calendar day (news day). YYYY-MM-DD.
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function daysBetween(a, b) {
    return Math.round((Date.parse(b + 'T00:00:00') - Date.parse(a + 'T00:00:00')) / 86400000);
  }

  // ── Device type (for analytics params) ──
  function deviceType() {
    if (window.matchMedia('(min-width:1024px)').matches && !('ontouchstart' in window)) return 'desktop';
    if (window.matchMedia('(min-width:768px)').matches) return 'tablet';
    return 'mobile';
  }

  // ═══════════════════════════════════════════
  // 1) ANALYTICS — thin, safe GA4 wrapper
  // ═══════════════════════════════════════════
  function track(name, params) {
    var payload = Object.assign({ device_type: deviceType() }, params || {});
    try { if (typeof window.gtag === 'function') window.gtag('event', name, payload); } catch (_) {}
    try { (window.dataLayer = window.dataLayer || []).push(Object.assign({ event: name }, payload)); } catch (_) {}
  }

  // Auto-track newsletter + push CTA views (once each) and clicks, from data-attrs.
  // Any element with [data-cta="newsletter"] and [data-cta-location] participates.
  function wireCtaTracking() {
    // View events via IntersectionObserver (fires once when 50% visible).
    if ('IntersectionObserver' in window) {
      var seen = new WeakSet();
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting || seen.has(en.target)) return;
          seen.add(en.target);
          var el = en.target;
          // Push-bar views are tracked by audience.js on reveal (with context),
          // so the observer only counts newsletter CTA impressions here.
          track('newsletter_cta_view', { cta_location: el.dataset.ctaLocation || 'unknown' });
          io.unobserve(el);
        });
      }, { threshold: 0.5 });
      document.querySelectorAll('[data-cta="newsletter"]').forEach(function (el) { io.observe(el); });
    }
    // Click events (submit for forms, click for buttons/links).
    document.querySelectorAll('form[data-cta="newsletter"]').forEach(function (f) {
      f.addEventListener('submit', function () {
        track('newsletter_cta_click', { cta_location: f.dataset.ctaLocation || 'unknown' });
      });
    });
  }

  // ═══════════════════════════════════════════
  // 2) PROMPT MANAGER — one interruptive ask at a time
  // ═══════════════════════════════════════════
  // Interruptive prompts (push bar, sub popup, cookie banner) register here so
  // they never stack and each respects its own cooldown. Inline CTAs (in-feed,
  // in-article) do NOT go through this — they don't interrupt.
  var LOG_KEY = 'ttd_prompt_log';        // { id: lastShownEpochMs }
  var GLOBAL_GAP_MS = 45 * 1000;         // min gap between ANY two interruptive prompts
  var lastAnyShown = 0;

  var Prompts = {
    // Is an interruptive prompt currently on screen?
    blocked: function () {
      var ids = ['cookieBanner', 'subPopup', 'pushBar'];
      for (var i = 0; i < ids.length; i++) {
        var el = document.getElementById(ids[i]);
        if (el && el.classList.contains('show')) return true;
      }
      return false;
    },
    // May prompt `id` be shown now? cooldownDays = don't repeat within N days.
    canShow: function (id, cooldownDays) {
      if (this.blocked()) return false;
      if (Date.now() - lastAnyShown < GLOBAL_GAP_MS) return false;
      var log = readJSON(LS, LOG_KEY, {});
      var last = log[id] || 0;
      if (cooldownDays && last && (Date.now() - last) < cooldownDays * 86400000) return false;
      return true;
    },
    markShown: function (id) {
      var log = readJSON(LS, LOG_KEY, {});
      log[id] = Date.now();
      writeJSON(LS, LOG_KEY, log);
      lastAnyShown = Date.now();
    },
    dismiss: function (id, days) { this.markShown(id); }, // dismissal == "shown" for cooldown purposes
  };

  // ═══════════════════════════════════════════
  // 3) READING FUNNEL — session + return signals
  // ═══════════════════════════════════════════
  var Funnel = {
    // Per-session set of article slugs viewed (drives "second article").
    recordArticle: function (slug, category, topics) {
      if (!slug) return { count: 0 };
      var seen = readJSON(SS, 'ttd_session_articles', []);
      var first = seen.indexOf(slug) === -1;
      if (first) seen.push(slug);
      writeJSON(SS, 'ttd_session_articles', seen);

      // Lifetime topic interest (for contextual push + personalization).
      if (topics && topics.length) {
        var interest = readJSON(LS, 'ttd_topic_interest', {});
        topics.forEach(function (t) {
          if (!t.slug) return;
          interest[t.slug] = interest[t.slug] || { name: t.name || t.slug, count: 0 };
          interest[t.slug].count++;
          interest[t.slug].name = t.name || interest[t.slug].name;
        });
        writeJSON(LS, 'ttd_topic_interest', interest);
      }

      var count = seen.length;
      if (count === 2 && first) {
        track('second_article_session', { article_slug: slug, article_category: category || '' });
      }
      return { count: count, isSecond: count === 2 };
    },
    sessionArticleCount: function () { return readJSON(SS, 'ttd_session_articles', []).length; },
    // Topics the reader reads most, most-read first.
    topTopics: function (limit) {
      var interest = readJSON(LS, 'ttd_topic_interest', {});
      return Object.keys(interest)
        .map(function (slug) { return { slug: slug, name: interest[slug].name, count: interest[slug].count }; })
        .sort(function (a, b) { return b.count - a.count; })
        .slice(0, limit || 5);
    },
    // Visit accounting (returning-visitor logic). Counts one visit per day max.
    // Also captures the PREVIOUS visit's timestamp so the homepage can show an
    // honest "N new stories since your last visit".
    recordVisit: function () {
      var last = LS.getItem('ttd_last_visit_day');
      var today = todayKey();
      var visits = parseInt(LS.getItem('ttd_visits') || '0', 10) || 0;
      var prevTs = parseInt(LS.getItem('ttd_last_visit_ts') || '0', 10) || 0;
      var isReturning = false, gapDays = null;
      if (last !== today) {
        visits++;
        LS.setItem('ttd_visits', String(visits));
        if (last) { gapDays = daysBetween(last, today); isReturning = true; }
        LS.setItem('ttd_prev_visit_day', last || '');
        LS.setItem('ttd_last_visit_day', today);
        LS.setItem('ttd_prev_visit_ts', String(prevTs || 0));
        LS.setItem('ttd_last_visit_ts', String(Date.now()));
      } else {
        isReturning = visits > 1;
        prevTs = parseInt(LS.getItem('ttd_prev_visit_ts') || '0', 10) || 0;
      }
      if (isReturning) track('returning_visitor', { visit_count: visits, days_since_last: gapDays });
      return { visits: visits, isReturning: isReturning, gapDays: gapDays, prevTs: prevTs };
    },
  };

  // ═══════════════════════════════════════════
  // 4) TOPIC FOLLOWING — localStorage-first, account-free
  // ═══════════════════════════════════════════
  // A follow = a stored preference { slug, name, alerts, email }. Push/email
  // delivery per-topic is a BACKEND TODO (see routes/PushController); until then
  // following a topic still (a) personalizes on-site and (b) can opt the reader
  // into global breaking alerts / the Morning Brief, which we CAN deliver today.
  var Topics = {
    all: function () { return readJSON(LS, 'ttd_follows', {}); },
    isFollowing: function (slug) { return !!this.all()[slug]; },
    // Real topic slugs only (excludes the "story-*" developing-story pseudo-follows,
    // which aren't Tag rows on the server).
    realSlugs: function () {
      return Object.keys(this.all()).filter(function (s) { return s.indexOf('story-') !== 0; });
    },
    follow: function (slug, name, opts) {
      var f = this.all();
      f[slug] = Object.assign({ slug: slug, name: name, alerts: false, email: false, at: Date.now() }, opts || {});
      writeJSON(LS, 'ttd_follows', f);
      track('topic_follow_success', { topic: slug, alerts: !!f[slug].alerts, email: !!f[slug].email });
      syncPushTopics();
      return f[slug];
    },
    unfollow: function (slug) {
      var f = this.all(); delete f[slug]; writeJSON(LS, 'ttd_follows', f);
      track('topic_unfollow', { topic: slug });
      syncPushTopics();
    },
    list: function () {
      var f = this.all();
      return Object.keys(f).map(function (k) { return f[k]; }).sort(function (a, b) { return b.at - a.at; });
    },
  };

  // Persist followed topics to the server against THIS browser's push endpoint
  // (no account needed). Best-effort: silent no-op if push isn't enabled yet.
  async function getPushEndpoint() {
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
      var reg = await navigator.serviceWorker.getRegistration();
      if (!reg) return null;
      var sub = await reg.pushManager.getSubscription();
      return sub ? sub.endpoint : null;
    } catch (_) { return null; }
  }
  async function syncPushTopics() {
    var endpoint = await getPushEndpoint();
    if (!endpoint) return; // not subscribed to push → nothing to key follows to yet
    var token = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
      await fetch('/push/topics', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ endpoint: endpoint, topics: Topics.realSlugs() }),
      });
    } catch (_) {}
  }

  // ═══════════════════════════════════════════
  // 5) QUIZ STREAKS — daily habit loop
  // ═══════════════════════════════════════════
  var Quiz = {
    state: function () {
      return readJSON(LS, 'ttd_quiz', { last: null, streak: 0, longest: 0, total: 0, lastScore: null, lastTotal: null });
    },
    doneToday: function () { return this.state().last === todayKey(); },
    // Record a completion. Anti-refresh: completing again the same day does NOT
    // bump the streak or total; it just updates the last score.
    record: function (score, total) {
      var s = this.state();
      var today = todayKey();
      if (s.last === today) {
        s.lastScore = score; s.lastTotal = total;
        writeJSON(LS, 'ttd_quiz', s);
        return Object.assign({ already: true }, s);
      }
      if (s.last && daysBetween(s.last, today) === 1) s.streak = (s.streak || 0) + 1;
      else s.streak = 1;
      s.last = today;
      s.total = (s.total || 0) + 1;
      s.longest = Math.max(s.longest || 0, s.streak);
      s.lastScore = score; s.lastTotal = total;
      writeJSON(LS, 'ttd_quiz', s);
      track('quiz_completed', { score: score, total: total });
      track('quiz_streak', { streak: s.streak, longest: s.longest });
      return Object.assign({ already: false }, s);
    },
  };

  // Public namespace.
  window.ttd = {
    track: track,
    prompts: Prompts,
    funnel: Funnel,
    topics: Topics,
    quiz: Quiz,
    device: deviceType,
    syncPushTopics: syncPushTopics,
  };

  // Hide owned-audience CTAs from people who already subscribed (no nagging).
  function hideForSubscribers() {
    if (LS.getItem('ttd_subscribed') !== '1' && LS.getItem('dp_subscribed') !== '1') return;
    document.querySelectorAll('[data-hide-if-subscribed]').forEach(function (el) { el.hidden = true; el.style.display = 'none'; });
  }

  // Homepage quiz card: streak-aware copy pulled from the local streak store.
  function enhanceQuizTeaser() {
    var card = document.querySelector('[data-quiz-teaser]');
    if (!card) return;
    var s = Quiz.state();
    var title = card.querySelector('[data-qt-title]');
    var sub = card.querySelector('[data-qt-sub]');
    var cta = card.querySelector('[data-qt-cta]');
    var icon = card.querySelector('[data-qt-icon]');
    if (Quiz.doneToday()) {
      if (icon) icon.textContent = '✓';
      if (title) title.textContent = 'Today’s Quiz Complete';
      if (sub) sub.textContent = (s.streak > 1 ? '🔥 ' + s.streak + '-day streak · ' : '') + 'Come back tomorrow to keep it going.';
      if (cta) cta.textContent = 'Review answers →';
      card.classList.add('quiz-teaser--done');
    } else if (s.streak >= 1 && s.last) {
      if (icon) icon.textContent = '🔥';
      if (title) title.textContent = 'Your ' + s.streak + '-day streak is waiting';
      if (sub) sub.textContent = 'Keep it alive — today’s 5 questions take about 2 minutes.';
      if (cta) cta.textContent = 'Take Today’s Quiz →';
    }
  }

  // Minimal CSRF-posted subscribe (for dynamically-built follow dialog).
  function postSubscribe(email, source, topics) {
    var token = document.querySelector('meta[name="csrf-token"]')?.content;
    return fetch('/subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ email: email, source: source || 'topic_follow', topics: topics || [] }),
    });
  }

  // ── Topic follow dialog (account-free) ──
  var dialogEl = null;
  function openFollowDialog(slug, name) {
    if (!dialogEl) {
      dialogEl = document.createElement('div');
      dialogEl.className = 'follow-dialog';
      dialogEl.innerHTML =
        '<div class="follow-dialog-card" role="dialog" aria-modal="true" aria-label="Follow topic">' +
          '<button class="follow-dialog-x" aria-label="Close">✕</button>' +
          '<h3 data-fd-title>Follow</h3>' +
          '<p class="follow-dialog-sub">How would you like updates?</p>' +
          '<button type="button" class="follow-dialog-alerts" data-fd-alerts>🔔 Breaking alerts</button>' +
          '<form class="follow-dialog-email" data-fd-emailform>' +
            '<input type="email" name="email" placeholder="your@email.com" aria-label="Email for daily updates" required />' +
            '<button type="submit">🇺🇸 Add daily email</button>' +
          '</form>' +
          '<p class="follow-dialog-note" data-fd-note>You’re following this topic on this device.</p>' +
          '<button type="button" class="follow-dialog-done" data-fd-done>Done</button>' +
        '</div>';
      document.body.appendChild(dialogEl);
      var close = function () { dialogEl.classList.remove('show'); };
      dialogEl.querySelector('.follow-dialog-x').addEventListener('click', close);
      dialogEl.querySelector('[data-fd-done]').addEventListener('click', close);
      dialogEl.addEventListener('click', function (e) { if (e.target === dialogEl) close(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

      dialogEl.querySelector('[data-fd-alerts]').addEventListener('click', async function () {
        var b = this; b.textContent = 'Enabling…';
        var ok = window.dpEnablePush ? await window.dpEnablePush() : false;
        var cur = dialogEl._slug;
        if (ok && cur) { var f = Topics.all(); if (f[cur]) { f[cur].alerts = true; writeJSON(LS, 'ttd_follows', f); } syncPushTopics(); }
        b.textContent = ok ? '✓ Breaking alerts on' : '🔔 Breaking alerts';
      });
      dialogEl.querySelector('[data-fd-emailform]').addEventListener('submit', async function (e) {
        e.preventDefault();
        var input = this.querySelector('input');
        var btn = this.querySelector('button');
        btn.textContent = 'Adding…';
        try {
          var res = await postSubscribe(input.value, 'topic_follow_' + (dialogEl._slug || ''), Topics.realSlugs());
          if (!res.ok) throw new Error();
          LS.setItem('ttd_subscribed', '1'); LS.setItem('dp_subscribed', '1');
          var cur = dialogEl._slug; if (cur) { var f = Topics.all(); if (f[cur]) { f[cur].email = true; writeJSON(LS, 'ttd_follows', f); } }
          track('newsletter_signup_success', { cta_location: 'topic_follow' });
          btn.textContent = '✓ Added';
        } catch (_) { btn.textContent = 'Try again'; }
      });
    }
    dialogEl._slug = slug;
    dialogEl.querySelector('[data-fd-title]').textContent = 'Follow ' + name;
    var alertsBtn = dialogEl.querySelector('[data-fd-alerts]');
    alertsBtn.textContent = '🔔 Breaking alerts';
    var emailBtn = dialogEl.querySelector('[data-fd-emailform] button');
    emailBtn.textContent = '🇺🇸 Add daily email';
    dialogEl.classList.add('show');
  }

  function wireTopicFollows() {
    document.querySelectorAll('[data-follow-topic]').forEach(function (btn) {
      var slug = btn.dataset.topicSlug, name = btn.dataset.topicName;
      var isStory = btn.hasAttribute('data-follow-story');
      var sync = function () {
        if (Topics.isFollowing(slug)) { btn.classList.add('following'); btn.innerHTML = isStory ? '✓ Following this story' : '✓ Following'; }
        else { btn.classList.remove('following'); btn.innerHTML = isStory ? '🔔 Follow this story' : '<span class="tf-plus">＋</span> Follow'; }
      };
      sync();
      btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (Topics.isFollowing(slug)) { Topics.unfollow(slug); sync(); return; }
        track('topic_follow_click', { topic: slug, story: isStory });
        Topics.follow(slug, name);
        sync();
        openFollowDialog(slug, name);
      });
    });
  }

  // ── General engagement analytics wiring ──
  function wireEngagementEvents() {
    document.querySelectorAll('[data-enable-push-inline]').forEach(function (b) {
      b.addEventListener('click', async function () {
        track('push_soft_prompt_accept', { context: 'inline' });
        b.textContent = 'Enabling…';
        var ok = window.dpEnablePush ? await window.dpEnablePush() : false;
        b.textContent = ok ? '✓ Alerts on' : 'Couldn’t enable';
      });
    });

    var cf = document.getElementById('cf_body');
    if (cf) cf.addEventListener('focus', function () { track('comment_started'); }, { once: true });
    var cform = document.getElementById('cf_form');
    if (cform) cform.addEventListener('submit', function () { track('comment_submitted'); });

    document.querySelectorAll('.up-next').forEach(function (a) {
      a.addEventListener('click', function () { track('up_next_click', { article_slug: a.getAttribute('href') }); });
    });
    document.querySelectorAll('.article-related a').forEach(function (a) {
      a.addEventListener('click', function () { track('related_article_click', { article_slug: a.getAttribute('href') }); });
    });
    document.querySelectorAll('form.poll-form').forEach(function (f) {
      f.addEventListener('submit', function () { track('poll_vote'); });
    });
  }

  // Returning-visitor welcome — honest count of new stories since last visit.
  function initReturnWelcome(prevTs) {
    var bar = document.getElementById('returnWelcome');
    if (!bar || !prevTs) return; // first-ever visit → nothing to say
    var el = document.getElementById('ttdRecentTimes');
    var times = [];
    try { times = JSON.parse(el?.textContent || '[]'); } catch (_) {}
    var prevSec = Math.floor(prevTs / 1000);
    var fresh = times.filter(function (t) { return t > prevSec; }).length;

    var bits = [];
    if (fresh > 0) bits.push(fresh + ' new ' + (fresh === 1 ? 'story' : 'stories'));
    var teaser = document.querySelector('[data-quiz-teaser]');
    if (teaser && !Quiz.doneToday()) bits.push('today’s quiz is ready');
    if (!bits.length) return; // nothing genuinely new → don't nag

    document.getElementById('returnWelcomeText').innerHTML =
      '<strong>Welcome back.</strong> Since your last visit: ' + bits.join(' · ') + '.';
    bar.hidden = false;
    requestAnimationFrame(function () { bar.classList.add('show'); });
    document.getElementById('returnWelcomeX')?.addEventListener('click', function () {
      bar.classList.remove('show'); setTimeout(function () { bar.hidden = true; }, 300);
    });
    track('returning_visitor_welcome', { new_stories: fresh });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var visit = Funnel.recordVisit();
    wireCtaTracking();
    hideForSubscribers();
    enhanceQuizTeaser();
    initReturnWelcome(visit.prevTs);
    wireTopicFollows();
    wireEngagementEvents();

    // Article page: record the read + drive the "second article" push nudge.
    var art = document.querySelector('[data-article-slug]');
    if (art) {
      var topics = (art.dataset.articleTopics || '')
        .split('|').filter(Boolean)
        .map(function (pair) { var p = pair.split('::'); return { slug: p[0], name: p[1] || p[0] }; });
      track('article_view', { article_slug: art.dataset.articleSlug, article_category: art.dataset.articleCategory || '' });
      var res = Funnel.recordArticle(art.dataset.articleSlug, art.dataset.articleCategory, topics);
      // On the SECOND article of the session, the reader is clearly engaged —
      // a great moment for the soft push ask (audience.js exposes the reveal).
      if (res.isSecond && typeof window.dpRevealPush === 'function') {
        setTimeout(function () { window.dpRevealPush('second_article'); }, 4000);
      }
    }
  });
})();
