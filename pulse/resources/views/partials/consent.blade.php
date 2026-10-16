{{-- First-party preferences. Advertising remains disabled until a certified CMP is integrated. --}}
<section class="cookie-banner" id="cookieBanner" aria-label="Cookie preferences" hidden>
  <div class="cookie-text">
    <strong>Cookie preferences</strong>
    <span>Essential storage keeps the site working. Optional analytics measures visits; optional referral tracking credits affiliates. Notifications have a separate signup. <a href="{{ route('page', 'privacy') }}">Privacy Policy</a>.</span>
    <fieldset id="cookieChoices" hidden>
      <legend>Optional purposes (off unless selected)</legend>
      <label><input type="checkbox" id="consentAnalytics"> Analytics</label>
      <label><input type="checkbox" id="consentMarketing"> Affiliate referral tracking</label>
      <p>Advertising is currently paused while its consent integration is configured.</p>
    </fieldset>
  </div>
  <div class="cookie-actions">
    <button type="button" class="cookie-btn cookie-decline" data-consent-reject>Essential only</button>
    <button type="button" class="cookie-btn cookie-decline" data-consent-customize aria-controls="cookieChoices" aria-expanded="false">Customize</button>
    <button type="button" class="cookie-btn cookie-accept" data-consent-save hidden>Save preferences</button>
    <button type="button" class="cookie-btn cookie-accept" data-consent-accept>Accept optional cookies</button>
  </div>
</section>
<noscript><p class="section">Optional analytics and advertising are disabled without JavaScript.</p></noscript>

{{-- Subscription popup --}}
<div class="sub-popup" id="subPopup">
  <div class="sub-popup-card">
    <button class="sub-popup-close" data-close aria-label="Close">✕</button>
    <div class="sub-popup-icon">🇺🇸</div>
    <h3>The Defender Morning Brief</h3>
    <p>Receive selected stories by email when a briefing is available. Free; unsubscribe anytime.</p>
    @if(\App\Support\PublicationInfo::newsletterAvailable())
    <form data-subscribe data-source="popup" data-cta="newsletter" data-cta-location="popup" class="sub-popup-form">
      <input type="email" name="email" placeholder="your@email.com" required />
      <button type="submit">Subscribe →</button>
    </form>
    @else
    <p>Email briefings are not currently available.</p>
    @endif
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
<div class="push-bar" id="pushBar" role="dialog" aria-label="Get breaking news alerts" data-cta="push" data-cta-location="push_soft_prompt" hidden>
  <div class="push-bar-inner">
    <span class="push-bar-icon" data-pb-icon>🔔</span>
    <div class="push-bar-text">
      <strong data-pb-title>Stay Ahead of Breaking News</strong>
      <span data-pb-sub>Receive selected story alerts in your browser.</span>
      <span class="push-bar-trust">Selected story alerts. Turn off anytime.</span>
    </div>
    <div class="push-bar-actions">
      <button class="push-bar-yes" type="button" data-push-yes>🔔 Enable story alerts</button>
      <button class="push-bar-no" type="button" data-push-no>Not Now</button>
    </div>
  </div>

  {{-- iPhone install steps (shown in place of the push button on iOS Safari) --}}
  <div class="push-bar-ios" id="pushBarIos" hidden>
    <span class="ios-step"><span class="ios-num">1</span><span class="ios-step-text">Tap the <strong>•••</strong> menu at the bottom of the screen, then tap <strong>Share</strong>
      <svg class="ios-share" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/></svg>
      <span class="ios-note">(in Safari, just tap <strong>Share</strong> in the bottom bar directly)</span></span></span>
    <span class="ios-step"><span class="ios-num">2</span><span class="ios-step-text">Scroll down the menu and tap <strong>Add to Home Screen</strong></span></span>
    <span class="ios-step"><span class="ios-num">3</span><span class="ios-step-text">Tap <strong>Add</strong> in the top-right corner</span></span>
    <span class="ios-step"><span class="ios-num">4</span><span class="ios-step-text">Open <strong>TheTrueDefender</strong> from your Home Screen, then tap <strong>🔔&nbsp;Subscribe</strong> and choose <strong>Allow</strong></span></span>
  </div>

  <div class="push-bar-note" id="pushBarNote" hidden></div>
</div>
