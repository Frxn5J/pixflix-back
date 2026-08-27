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
        'number',
        'title',
        'url',
        'image',
        'release_date',
        'extract_url',
        'streams',
    ];

    protected $casts = [
        'number' => 'integer',
        'streams' => 'array',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
