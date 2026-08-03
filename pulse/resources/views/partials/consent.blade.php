{{-- Cookie consent banner --}}
<div class="cookie-banner" id="cookieBanner">
  <div class="cookie-text">
    <strong>We value your privacy 🍪</strong>
    <span>We use cookies for essential features and, if you allow it, to send you a notification whenever we publish a new story. See our <a href="{{ route('page', 'privacy') }}">Privacy Policy</a>.</span>
  </div>
  <div class="cookie-actions">
    <button class="cookie-btn cookie-decline" data-decline>Decline</button>
    <button class="cookie-btn cookie-accept" data-accept>Accept &amp; Notify Me</button>
  </div>
</div>

{{-- Subscription popup --}}
<div class="sub-popup" id="subPopup">
  <div class="sub-popup-card">
    <button class="sub-popup-close" data-close aria-label="Close">✕</button>
    <div class="sub-popup-icon">📨</div>
    <h3>Never Miss a Story</h3>
    <p>Get the headlines that matter delivered straight to your inbox.</p>
    <form data-subscribe data-source="popup" class="sub-popup-form">
      <input type="email" name="email" placeholder="your@email.com" required />
      <button type="submit">Subscribe</button>
    </form>
    <button class="sub-popup-push" data-enable-push>🔔 Or enable browser notifications</button>
    <div class="ios-push-hint" id="iosPushHint" hidden>
      📱 <strong>On iPhone:</strong> tap the <strong>Share</strong> icon, choose <strong>“Add to Home Screen”</strong>, then open TheTrueDefender from your Home Screen and tap 🔔 to turn on notifications. <span style="opacity:.7">(Apple only allows notifications for saved web apps.)</span>
    </div>
    <button class="sub-popup-dismiss" data-close>No thanks</button>
  </div>
</div>

{{-- Smart notification opt-in bar: engagement-triggered, platform-aware, value-led.
     Only fires after the reader engages (scroll / dwell / return visit), asks softly
     first, and only then triggers the real browser permission — so a reflexive "Block"
     never permanently burns the visitor. --}}
<div class="push-bar" id="pushBar" role="dialog" aria-label="Get news alerts" hidden>
  <div class="push-bar-inner">
    <span class="push-bar-icon" data-pb-icon>🔔</span>
    <div class="push-bar-text">
      {{-- To use the shop-tied hook, swap the sub line for:
           "Be the first to know when big US news breaks — and when we drop free gifts." --}}
      <strong data-pb-title>Never miss a breaking story</strong>
      <span data-pb-sub>Get free alerts the moment big US news breaks.</span>
    </div>
    <div class="push-bar-actions">
      <button class="push-bar-yes" type="button" data-push-yes>Yes, notify me</button>
      <button class="push-bar-no" type="button" data-push-no>Not now</button>
    </div>
  </div>

  {{-- iPhone install steps (shown in place of the push button on iOS Safari) --}}
  <div class="push-bar-ios" id="pushBarIos" hidden>
    <span class="ios-step"><span class="ios-num">1</span> Tap the Share icon
      <svg class="ios-share" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/></svg>
      in the bar at the bottom of Safari</span>
    <span class="ios-step"><span class="ios-num">2</span> Scroll down and tap <strong>Add to Home Screen</strong>, then <strong>Add</strong> in the top corner</span>
    <span class="ios-step"><span class="ios-num">3</span> Open <strong>TheTrueDefender</strong> from your Home Screen (it now works like an app)</span>
    <span class="ios-step"><span class="ios-num">4</span> Tap the <strong>🔔 Subscribe</strong> button and choose <strong>Allow</strong> — you're all set for breaking-news alerts</span>
  </div>

  <div class="push-bar-note" id="pushBarNote" hidden></div>
</div>
