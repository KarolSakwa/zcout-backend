<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoutReportSubmission extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'player_id',
        'ratings_count',
        'pre_overall',
        'post_overall',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'player_id' => 'int',
        'ratings_count' => 'int',
        'pre_overall' => 'float',
        'post_overall' => 'float',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'scout_report_submission_id');
    }
}
