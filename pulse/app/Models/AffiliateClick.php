<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateClick extends Model
{
    protected $fillable = [
        'affiliate_id', 'ip_hash', 'ua_hash', 'path', 'is_valid', 'invalid_reason', 'earnings',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'earnings' => 'float',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
