<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollOption extends Model
{
    protected $fillable = ['poll_id', 'label', 'votes', 'sort_order'];

    protected $casts = ['votes' => 'integer', 'sort_order' => 'integer'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }
}
