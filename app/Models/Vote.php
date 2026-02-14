<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'int',
        'attribute_id' => 'int',
        'duel_id' => 'int',
        'player_a_id' => 'int',
        'player_b_id' => 'int',
        'winner_id' => 'int',
        'user_id' => 'int',
        'value' => 'int',
        'weight_applied' => 'float',
        'weight_version' => 'int',
        'reputation_at_vote' => 'float',
        'risk_score_at_vote' => 'float',
        'pre_rating_a' => 'float',
        'pre_rating_b' => 'float',
        'post_rating_a' => 'float',
        'post_rating_b' => 'float',
        'created_at' => 'datetime',
    ];
}
