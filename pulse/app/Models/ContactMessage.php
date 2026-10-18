<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message sent through the public contact form. */
class ContactMessage extends Model
{
    /** Topic key => label shown in the form (and used to route the notification). */
    public const TOPICS = [
        'tip' => 'News tip or story idea',
        'correction' => 'Correction',
        'support' => 'Support or feedback',
        'privacy' => 'Privacy request',
        'other' => 'Something else',
    ];

    protected $fillable = ['topic', 'name', 'email', 'subject', 'message', 'ip_hash', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];
}
