<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerAttributeRating extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'player_id',
        'attribute_id',
        'rating',
        'votes_count',
    ];
}
