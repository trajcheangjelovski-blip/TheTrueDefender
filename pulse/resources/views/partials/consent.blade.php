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
    <p>Join thousands of readers getting the headlines that matter — straight to your inbox.</p>
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
