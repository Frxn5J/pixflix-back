<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'tmdb_id',
        'imdb_id',
        'source',
        'source_playlist_id',
        'is_active',
        'slug',
        'type',
        'title',
        'description',
        'poster',
        'gallery',
        'rating',
        'year',
        'quality',
        'languages',
        'genres',
        'category',
        'total_seasons',
        'total_episodes',
        'raw_extract',
        'stream_url',
        'stream_headers',
        'metadata',
        'snapshot_version',
    ];

    protected $casts = [
        'gallery' => 'array',
        'tmdb_id' => 'integer',
        'languages' => 'array',
        'genres' => 'array',
        'raw_extract' => 'array',
        'stream_headers' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'total_seasons' => 'integer',
        'total_episodes' => 'integer',
        'snapshot_version' => 'integer',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CatalogSnapshot::class, 'snapshot_version', 'version');
    }
}
