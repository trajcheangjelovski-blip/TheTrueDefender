<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\ConsentRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyConsentTest extends TestCase
{
    use RefreshDatabase;

    private function consentConfig(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.TTD_CONSENT = (\{.*?\});/', $html, $m));

        return json_decode($m[1], true);
    }

    public function test_region_decides_whether_prior_consent_is_required(): void
    {
        $get = fn (array $h) => $this->flushHeaders()->withHeaders($h)->get('/')->assertOk()->getContent();

        $this->assertTrue($this->consentConfig($get(['CF-IPCountry' => 'DE']))['required']);
        $this->assertTrue($this->consentConfig($get(['CF-IPCountry' => 'GB']))['required']);
        $this->assertTrue($this->consentConfig($get(['CF-IPCountry' => 'CH']))['required']);
        $this->assertFalse($this->consentConfig($get(['CF-IPCountry' => 'US']))['required']);
        $this->assertTrue($this->consentConfig($get([]))['required'], 'unknown region is treated as required');
        $this->assertTrue($this->consentConfig($get(['CF-IPCountry' => 'XX']))['required']);
        $this->assertTrue($this->consentConfig($get(['CF-IPCountry' => 'T1']))['required']);
        $this->assertContains('FR', ConsentRegion::REQUIRED);
    }

    public function test_page_has_no_homemade_banner_no_direct_trackers_and_loads_the_cmp_and_consent_script(): void
    {
        Setting::put('adsense_client', 'ca-pub-1234567890');
        Setting::put('ga_measurement_id', 'G-TEST123');
        $html = $this->withHeaders(['CF-IPCountry' => 'DE'])->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Accept &amp; Notify Me', $html);
        $this->assertStringNotContainsString('Accept & Notify Me', $html);
        $this->assertStringNotContainsString('cookieBanner', $html);
        // Analytics and ad scripts are never in the HTML: consent.js adds them only when permitted.
        $this->assertStringNotContainsString('googletagmanager.com/gtag/js', $html);
        $this->assertStringNotContainsString('googlesyndication.com', $html);
        // Google's Privacy & messaging tag (derived from the AdSense publisher id) and our consent logic.
        $this->assertStringContainsString('https://fundingchoicesmessages.google.com/i/pub-1234567890?ers=1', $html);
        $this->assertStringContainsString("name='googlefcPresent'", str_replace('"', "'", $html));
        $this->assertStringContainsString('js/consent.js', $html);
        $cfg = $this->consentConfig($html);
        $this->assertSame('G-TEST123', $cfg['ga']);
        $this->assertSame('ca-pub-1234567890', $cfg['ads']);
        // Push and newsletter are still separate, opt-in controls.
        $this->assertStringContainsString('data-enable-push', $html);
        $this->assertStringNotContainsString('data-accept', $html);
    }

    public function test_cmp_tag_is_omitted_when_advertising_is_not_configured(): void
    {
        Setting::put('adsense_client', '');
        config(['services.adsense.client' => '']);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('fundingchoicesmessages', $html);
    }

    public function test_privacy_choices_can_be_reopened_from_the_footer_and_the_policy(): void
    {
        $this->get('/')->assertSee('data-privacy-choices', false)->assertSee('Privacy choices');
        $page = $this->get('/privacy')->assertOk();
        $page->assertSee('id="choices"', false)->assertSee('reopen your privacy choices');
    }

    public function test_privacy_policy_has_a_fixed_date_and_no_ended_programs(): void
    {
        $html = $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringContainsString('Last updated: October 18, 2026', $html);
        $this->assertStringNotContainsString('purchase from our shop', $html);
        $this->assertStringNotContainsString('ship shop orders', $html);
        $this->assertStringNotContainsString('payment information', $html);
        $this->assertStringContainsString('policies.google.com/technologies/partner-sites', $html);
        $this->assertStringContainsString('support@thetruedefender.news', $html);
    }

    public function test_the_policy_page_source_has_no_automatically_changing_date(): void
    {
        $src = file_get_contents(resource_path('views/pages/privacy.blade.php'));
        $this->assertStringNotContainsString("date('F j, Y')", $src);
    }

    public function test_policy_says_no_ads_when_advertising_is_off_and_describes_them_when_on(): void
    {
        Setting::put('adsense_client', '');
        config(['services.adsense.client' => '']);
        $off = $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringContainsString('We do not currently display advertisements. If we introduce advertising, we will update this privacy policy', $off);
        $this->assertStringNotContainsString('Google AdSense', $off);
        $this->assertStringNotContainsString('Optional: advertising', $off);
        $this->assertStringNotContainsString('technologies/ads', $off);

        Setting::put('adsense_client', 'ca-pub-1234567890');
        $on = $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringNotContainsString('We do not currently display advertisements', $on);
        $this->assertStringContainsString('Optional: advertising', $on);
        $this->assertStringContainsString('Google AdSense', $on);
    }
}
