<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id', 'name', 'logo', 'category', 'country', 'language',
        'stream_url', 'stream_headers', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'stream_headers' => 'array'];

    public function epgEntries(): HasMany
    {
        return $this->hasMany(EpgEntry::class);
    }
}
