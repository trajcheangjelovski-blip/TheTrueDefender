// Run: node tests/js/consent.test.cjs
// Exercises public/js/consent.js and the consent gating in public/js/engage.js with stub browser objects.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

function makeEnv(cfg) {
  const store = {};
  const scripts = [];
  const fetches = [];
  const listeners = {};
  const el = () => new Proxy(function () {}, {
    get(t, k) {
      if (k === 'classList') return { add() {}, remove() {}, contains() { return false; }, toggle() {} };
      if (k === 'dataset' || k === 'style') return {};
      if (k === 'children' || k === 'childNodes') return [];
      if (k === 'content') return 'tok';
      if (k === 'textContent' || k === 'innerHTML' || k === 'value') return '';
      if (k === 'querySelector' || k === 'closest') return () => null;
      if (k === 'querySelectorAll') return () => [];
      if (k === 'getBoundingClientRect') return () => ({ top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 });
      if (k === Symbol.toPrimitive) return () => '';
      return typeof k === 'symbol' ? undefined : el();
    },
    set() { return true; },
    apply() { return el(); },
  });
  const document = {
    head: { appendChild(s) { scripts.push(s.src); } },
    body: el(),
    visibilityState: 'visible',
    createElement() { return {}; },
    querySelector(sel) { return /ttd-quiz-epoch|csrf-token/.test(sel) ? { content: 'tok' } : null; },
    querySelectorAll() { return []; },
    getElementById() { return null; },
    addEventListener(n, f) { (listeners[n] = listeners[n] || []).push(f); },
  };
  const window = {
    TTD_CONSENT: cfg,
    localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    addEventListener() {},
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    location: { pathname: '/', href: '/' },
    crypto: { randomUUID: () => 'uuid-test-1' },
    requestIdleCallback: (f) => setTimeout(f, 0),
    document,
  };
  window.window = window;
  const timers = [];
  const ctx = vm.createContext({
    window, document, console, URL, Date, JSON, Math, Promise, Object, Array, String, parseInt, encodeURIComponent,
    navigator: { userAgent: 'test', platform: 'x', maxTouchPoints: 0 },
    localStorage: window.localStorage, sessionStorage: window.sessionStorage, location: window.location,
    setTimeout, clearTimeout, setInterval: (f) => { timers.push(f); return timers.length; }, clearInterval() {},
    fetch: (url, opts) => { fetches.push({ url, body: opts && opts.body }); return Promise.resolve({ ok: true }); },
    requestIdleCallback: window.requestIdleCallback,
    crypto: window.crypto,
  });
  return { ctx, window, store, scripts, fetches, listeners, document };
}

function load(env, file) {
  vm.runInContext(fs.readFileSync(file, 'utf8'), env.ctx, { filename: file });
}
const tick = () => new Promise((r) => setTimeout(r, 30));
const tc = (status, purposes) => ({ eventStatus: status, purpose: { consents: purposes } });
const fireLoaded = (env) => (env.listeners.DOMContentLoaded || []).forEach((f) => { try { f(); } catch (e) { if (process.env.DEBUG) console.log('handler error:', e.message); } });

(async () => {
  let cb;
  const cmp = (env) => { env.window.__tcfapi = (cmd, v, f) => { cb = f; }; };

  // 1. Consent required, no CMP: nothing optional loads or is stored.
  let env = makeEnv({ required: true, ga: 'G-TEST', ads: 'ca-pub-1' });
  load(env, 'public/js/consent.js');
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), false);
  assert.deepStrictEqual(env.scripts, [], 'no GA or ad script without consent');
  let ran = 0;
  env.window.ttdConsent.onAnalytics(() => { ran++; });
  assert.strictEqual(ran, 0);
  console.log('ok  required region, no CMP: nothing loads');

  // 2. Dismissing the message (useractioncomplete with no consent) is not consent.
  env = makeEnv({ required: true, ga: 'G-TEST', ads: 'ca-pub-1' });
  cmp(env);
  load(env, 'public/js/consent.js');
  await tick();
  assert.ok(cb, 'listens to the CMP');
  cb(tc('cmpuishown', {}), true);
  cb(tc('useractioncomplete', {}), true);
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), false);
  assert.ok(!env.scripts.some((s) => s.includes('googletagmanager')), 'GA stays off after rejection');
  console.log('ok  rejection / dismissal: analytics stays off');

  // 3. Acceptance: purposes 1 and 8 turn analytics on, load GA, run queued callbacks.
  env = makeEnv({ required: true, ga: 'G-TEST', ads: 'ca-pub-1' });
  cmp(env);
  load(env, 'public/js/consent.js');
  ran = 0;
  env.window.ttdConsent.onAnalytics(() => { ran++; });
  await tick();
  cb(tc('useractioncomplete', { 1: true, 8: true }), true);
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), true);
  assert.ok(env.scripts.some((s) => s.includes('googletagmanager.com/gtag/js?id=G-TEST')));
  assert.strictEqual(ran, 1);
  console.log('ok  acceptance: analytics loads and queued work runs once');

  // 4. Only one of the needed purposes is not enough.
  env = makeEnv({ required: true, ga: 'G-TEST', ads: '' });
  cmp(env);
  load(env, 'public/js/consent.js');
  await tick();
  cb(tc('useractioncomplete', { 1: true }), true);
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), false);
  console.log('ok  partial consent does not enable analytics');

  // 5. Withdrawal: the first-party visitor id is deleted and analytics turns off.
  env = makeEnv({ required: true, ga: 'G-TEST', ads: '' });
  cmp(env);
  load(env, 'public/js/consent.js');
  await tick();
  cb(tc('useractioncomplete', { 1: true, 8: true }), true);
  await tick();
  env.store.ttd_vid = 'abc';
  cb(tc('useractioncomplete', {}), true);
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), false);
  assert.ok(!('ttd_vid' in env.store), 'visitor id removed on withdrawal');
  console.log('ok  withdrawal: id removed, analytics off');

  // 6. Region where consent is not required: analytics loads immediately.
  env = makeEnv({ required: false, ga: 'G-TEST', ads: 'ca-pub-1' });
  load(env, 'public/js/consent.js');
  await tick();
  assert.strictEqual(env.window.ttdConsent.analytics(), true);
  assert.ok(env.scripts.some((s) => s.includes('googletagmanager')));
  console.log('ok  non-required region: analytics on');

  // 7. Ads: in a required region the ad script waits for the CMP, then loads.
  env = makeEnv({ required: true, ga: '', ads: 'ca-pub-1' });
  cmp(env);
  load(env, 'public/js/consent.js');
  await tick();
  assert.ok(!env.scripts.some((s) => s.includes('adsbygoogle')), 'no ad script before the CMP reports');
  cb(tc('tcloaded', {}), true);
  await tick();
  assert.ok(env.scripts.some((s) => s.includes('adsbygoogle.js?client=ca-pub-1')), 'ad script loads once the CMP has loaded');
  console.log('ok  ads wait for the CMP');

  // 8. engage.js: presence heartbeat, visitor id and visit memory respect consent.
  const engage = 'public/js/engage.js';
  env = makeEnv({ required: true, ga: '', ads: '' });
  cmp(env);
  load(env, 'public/js/consent.js');
  load(env, engage);
  fireLoaded(env);
  await tick();
  assert.deepStrictEqual(env.fetches.filter((f) => f.url === '/track/heartbeat'), [], 'no heartbeat before consent');
  assert.ok(!('ttd_vid' in env.store) && !('ttd_visits' in env.store), 'nothing stored before consent');
  cb(tc('useractioncomplete', { 1: true, 8: true }), true);
  await tick();
  assert.strictEqual(env.fetches.filter((f) => f.url === '/track/heartbeat').length, 1, 'heartbeat starts after consent');
  assert.strictEqual(env.store.ttd_vid, 'uuid-test-1');
  cb(tc('useractioncomplete', {}), true);
  await tick();
  assert.ok(!('ttd_vid' in env.store), 'id removed after withdrawal');
  console.log('ok  engage.js: heartbeat and id follow consent (accept, then withdraw)');

  // 9. Region that does not require consent: heartbeat runs on load.
  env = makeEnv({ required: false, ga: '', ads: '' });
  load(env, 'public/js/consent.js');
  load(env, engage);
  fireLoaded(env);
  await tick();
  assert.strictEqual(env.fetches.filter((f) => f.url === '/track/heartbeat').length, 1);
  console.log('ok  non-required region: heartbeat runs');

  // 10. Quiz progress is versioned: saved progress from another epoch is discarded, other keys untouched.
  env = makeEnv({ required: false, ga: '', ads: '' });
  env.document.querySelector = (sel) => (/ttd-quiz-epoch/.test(sel) ? { content: '3' } : /csrf-token/.test(sel) ? { content: 'tok' } : null);
  load(env, 'public/js/consent.js');
  load(env, engage);
  env.store.ttd_quiz = JSON.stringify({ last: '2026-10-01', streak: 4, longest: 4, total: 4, epoch: '2' });
  env.store.ttd_followed = 'keep-me';
  const st = env.window.ttd.quiz.state();
  assert.strictEqual(st.streak, 0, 'old-epoch progress is ignored');
  assert.ok(!('ttd_quiz' in env.store), 'old quiz key removed');
  assert.strictEqual(env.store.ttd_followed, 'keep-me', 'unrelated storage untouched');
  env.window.ttd.quiz.record(4, 5);
  assert.strictEqual(JSON.parse(env.store.ttd_quiz).epoch, '3');
  console.log('ok  quiz progress is versioned by epoch');

  console.log('\nAll consent and quiz-state checks passed.');
})().catch((e) => { console.error('FAIL', e); process.exit(1); });
