<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateConversion;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The affiliate program engine: validates + records referred visits, and
 * attributes shop orders to the referring affiliate.
 *
 * IMPORTANT (AdSense safety): affiliates are paid for VISITS they refer,
 * never for ad clicks. Only unique human visits count — bots are filtered,
 * repeat visits are deduplicated, and per-IP volume is capped, so junk
 * traffic earns nothing.
 */
class AffiliateProgram
{
    /** Referral cookie name (read at checkout for sale attribution). */
    public const COOKIE = 'aff_ref';

    /** A visitor (ip+ua) counts once per this window. */
    private const DEDUP_HOURS = 24;

    /** Max valid clicks per IP (any browser) per affiliate per window. */
    private const IP_CAP = 3;

    private const BOT_UA = '/bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|phantom|scrapy|facebookexternalhit|preview/i';

    public function cookieMinutes(): int
    {
        return (int) \App\Models\Setting::get('affiliate_cookie_days', 30) * 24 * 60;
    }

    /** Record a referred visit. Returns the click (valid or flagged). */
    public function recordClick(Request $request, Affiliate $affiliate): AffiliateClick
    {
        $ua = (string) $request->userAgent();
        $ipHash = hash('sha256', (string) $request->ip());
        $uaHash = hash('sha256', $ua);
        $since = now()->subHours(self::DEDUP_HOURS);

        $invalidReason = null;

        if ($ua === '' || preg_match(self::BOT_UA, $ua)) {
            $invalidReason = 'bot';
        } elseif ($affiliate->clicks()
            ->where('ip_hash', $ipHash)->where('ua_hash', $uaHash)
            ->where('created_at', '>=', $since)->exists()) {
            $invalidReason = 'duplicate';
        } elseif ($affiliate->clicks()
            ->where('ip_hash', $ipHash)->where('is_valid', true)
            ->where('created_at', '>=', $since)->count() >= self::IP_CAP) {
            $invalidReason = 'ip-rate-limit';
        }

        return AffiliateClick::create([
            'affiliate_id' => $affiliate->id,
            'ip_hash' => $ipHash,
            'ua_hash' => $uaHash,
            'path' => Str::limit($request->path(), 490),
            'is_valid' => $invalidReason === null,
            'invalid_reason' => $invalidReason,
            // Lock in the payout at TODAY's rate — future rate changes only
            // affect future clicks, never already-earned balances.
            'earnings' => $invalidReason === null ? round($affiliate->earningsPerClick(), 4) : 0,
        ]);
    }

    /**
     * Attribute a placed order to the referring affiliate (if any).
     * Called from checkout with the referral cookie value.
     */
    public function recordConversion(Order $order, ?string $code): ?AffiliateConversion
    {
        if (blank($code)) {
            return null;
        }

        $affiliate = Affiliate::where('code', $code)->where('status', 'active')->first();
        if (! $affiliate) {
            return null;
        }

        $pct = $affiliate->effectiveSaleCommissionPct();

        return AffiliateConversion::firstOrCreate(
            ['order_id' => $order->id],
            [
                'affiliate_id' => $affiliate->id,
                'order_total' => $order->total,
                'commission_pct' => $pct,
                'commission_amount' => round($order->total * $pct / 100, 2),
                'status' => 'pending',
            ],
        );
    }
}
