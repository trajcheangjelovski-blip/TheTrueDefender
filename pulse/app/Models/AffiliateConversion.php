<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateConversion extends Model
{
    protected $fillable = [
        'affiliate_id', 'order_id', 'order_total', 'commission_pct', 'commission_amount', 'status',
    ];

    protected $casts = [
        'order_total' => 'float',
        'commission_pct' => 'float',
        'commission_amount' => 'float',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
