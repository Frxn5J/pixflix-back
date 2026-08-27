<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    public const ACCESSIBLE_STATUSES = ['active', 'expiring', 'trial'];

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'is_trial',
        'trial_expires_at',
        'group_number',
        'starts_at',
        'ends_at',
        'custom_price',
        'created_by',
        'whatsapp_ref',
    ];

    protected $casts = [
        'is_trial' => 'boolean',
        'trial_expires_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'custom_price' => 'decimal:2',
        'group_number' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function allowsAccess(): bool
    {
        if (! in_array($this->status, self::ACCESSIBLE_STATUSES, true)) {
            return false;
        }

        $expiresAt = $this->is_trial
            ? ($this->trial_expires_at ?? $this->ends_at)
            : $this->ends_at;

        return $expiresAt === null || $expiresAt->isFuture();
    }

    public function isTrialExpired(): bool
    {
        return $this->is_trial
            && ($this->trial_expires_at ?? $this->ends_at)?->isPast() === true;
    }
}
