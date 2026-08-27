<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'user_id',
        'source',
        'event',
        'title_id',
        'episode_id',
        'quality',
        'request_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
