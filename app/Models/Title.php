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
        'snapshot_version',
    ];

    protected $casts = [
        'gallery' => 'array',
        'languages' => 'array',
        'genres' => 'array',
        'raw_extract' => 'array',
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
