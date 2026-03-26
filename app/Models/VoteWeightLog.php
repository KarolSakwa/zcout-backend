<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoteWeightLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vote_id',
        'weight_version',
        'rating_algorithm_version',
        'base_duel_weight',
        'auth_factor',
        'trust_rating_factor',
        'trust_confidence_factor',
        'integrity_factor',
        'bias_factor',
        'activity_factor',
        'role_factor',
        'rating_weight_applied',
        'confidence_weight_applied',
    ];

    protected function casts(): array
    {
        return [
            'vote_id' => 'integer',
            'weight_version' => 'integer',
            'rating_algorithm_version' => 'integer',
            'base_duel_weight' => 'decimal:4',
            'auth_factor' => 'decimal:4',
            'trust_rating_factor' => 'decimal:4',
            'trust_confidence_factor' => 'decimal:4',
            'integrity_factor' => 'decimal:4',
            'bias_factor' => 'decimal:4',
            'activity_factor' => 'decimal:4',
            'role_factor' => 'decimal:4',
            'rating_weight_applied' => 'decimal:4',
            'confidence_weight_applied' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(Vote::class);
    }
}
