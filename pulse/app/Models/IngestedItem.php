<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestedItem extends Model
{
    protected $fillable = [
        'ingest_source_id', 'guid', 'source_url', 'title', 'status', 'post_id', 'error',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(IngestSource::class, 'ingest_source_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
