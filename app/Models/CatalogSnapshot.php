<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'started_at',
        'finished_at',
        'status',
        'stats',
        'checkpoint',
        'error',
        'triggered_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'stats' => 'array',
        'checkpoint' => 'array',
    ];

    public function titles(): HasMany
    {
        return $this->hasMany(Title::class, 'snapshot_version', 'version');
    }

    public static function current(): ?self
    {
        return static::query()->where('status', 'success')->latest('version')->first();
    }
}
