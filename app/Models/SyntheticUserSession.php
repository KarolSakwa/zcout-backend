<?php

namespace App\Models;

use App\Simulation\Synthetic\SyntheticSessionStatuses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyntheticUserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'planned_actions',
        'completed_actions',
        'next_action_at',
        'started_at',
        'completed_at',
        'session_seed',
        'last_action_status',
        'last_action_reason',
        'activity_date',
        'daily_session_index',
        'scheduled_start_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_actions' => 'integer',
            'completed_actions' => 'integer',
            'next_action_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'activity_date' => 'date',
            'daily_session_index' => 'integer',
            'scheduled_start_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === SyntheticSessionStatuses::ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === SyntheticSessionStatuses::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === SyntheticSessionStatuses::FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === SyntheticSessionStatuses::CANCELLED;
    }
}
