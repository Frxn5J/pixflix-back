<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id', 'source_playlist_id', 'name', 'logo', 'category', 'country', 'language',
        'stream_url', 'stream_headers', 'use_proxy', 'is_active',
        'stream_checked_at', 'stream_check_status', 'stream_check_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'use_proxy' => 'boolean',
        'stream_headers' => 'array',
        'stream_checked_at' => 'datetime',
    ];

    public function epgEntries(): HasMany
    {
        return $this->hasMany(EpgEntry::class);
    }
}
