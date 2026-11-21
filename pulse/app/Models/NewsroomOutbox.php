<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsroomOutbox extends Model
{
    protected $table = 'newsroom_outbox';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime'];
}
