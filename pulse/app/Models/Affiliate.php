<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class Affiliate extends Authenticatable
{
    protected $fillable = [
        'name', 'email', 'password', 'code', 'status', 'website', 'notes',
        'payout_method', 'rate_per_1000', 'share_pct', 'sale_commission_pct',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'rate_per_1000' => 'float',
        'share_pct' => 'float',
        'sale_commission_pct' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Affiliate $affiliate) {
            if (blank($affiliate->code)) {
                do {
                    $code = Str::upper(Str::random(8));
                } while (static::where('code', $code)->exists());
                $affiliate->code = $code;
            }
        });
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    /** Their shareable link. */
    public function referralUrl(): string
    {
        return url('/') . '?ref=' . $this->code;
    }

    // ── Effective rates (per-affiliate override, else global setting) ──

    public function effectiveRatePer1000(): float
    {
        return (float) ($this->rate_per_1000 ?? Setting::get('affiliate_rate_per_1000', 6));
    }

    public function effectiveSharePct(): float
    {
        return (float) ($this->share_pct ?? Setting::get('affiliate_share_pct', 70));
    }

    public function effectiveSaleCommissionPct(): float
    {
        return (float) ($this->sale_commission_pct ?? Setting::get('affiliate_sale_commission_pct', 10));
    }

    /** What the affiliate earns per single valid visit. */
    public function earningsPerClick(): float
    {
        return $this->effectiveRatePer1000() / 1000 * ($this->effectiveSharePct() / 100);
    }

    // ── Earnings & balance ──

    public function validClicksCount(): int
    {
        return $this->clicks()->where('is_valid', true)->count();
    }

    public function clickEarnings(): float
    {
        // Sum of per-click earnings locked in at click time (rate changes
        // only apply to clicks that happen after the change).
        return round((float) $this->clicks()->where('is_valid', true)->sum('earnings'), 2);
    }

    public function saleEarnings(): float
    {
        return (float) $this->conversions()
            ->whereIn('status', ['approved', 'paid'])
            ->sum('commission_amount');
    }

    public function totalEarned(): float
    {
        return round($this->clickEarnings() + $this->saleEarnings(), 2);
    }

    public function totalPaid(): float
    {
        return (float) $this->payouts()->sum('amount');
    }

    public function balance(): float
    {
        return round($this->totalEarned() - $this->totalPaid(), 2);
    }
}
