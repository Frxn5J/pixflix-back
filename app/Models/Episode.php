<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Episode extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'source',
        'source_playlist_id',
        'is_active',
        'number',
        'title',
        'url',
        'image',
        'release_date',
        'extract_url',
        'streams',
        'stream_url',
        'stream_headers',
    ];

    protected $casts = [
        'number' => 'integer',
        'streams' => 'array',
        'stream_headers' => 'array',
        'is_active' => 'boolean',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
