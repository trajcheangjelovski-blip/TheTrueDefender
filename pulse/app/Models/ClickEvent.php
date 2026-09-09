<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClickEvent extends Model
{
    const UPDATED_AT = null; // only track created_at

    protected $fillable = ['post_id', 'visitor_id', 'country', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** ISO alpha-2 country code → flag emoji (🏳️ when unknown). */
    public static function flag(?string $code): string
    {
        if (! $code || strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '🏳️';
        }

        $code = strtoupper($code);
        $flag = '';
        foreach (str_split($code) as $ch) {
            $flag .= mb_chr(0x1F1E6 + ord($ch) - ord('A'));
        }

        return $flag;
    }
}
