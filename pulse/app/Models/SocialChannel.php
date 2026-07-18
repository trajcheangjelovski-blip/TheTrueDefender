<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialChannel extends Model
{
    protected $fillable = ['driver', 'name', 'is_active', 'config'];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];
}
