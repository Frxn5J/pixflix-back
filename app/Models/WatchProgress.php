<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProgress extends Model
{
    use HasFactory;

    protected $table = 'watch_progress';

    protected $fillable = [
        'profile_id',
        'title_id',
        'episode_id',
        'season_id',
        'position_sec',
        'duration_sec',
        'percent',
        'completed',
    ];

    protected $casts = [
        'position_sec' => 'integer',
        'duration_sec' => 'integer',
        'percent' => 'float',
        'completed' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
