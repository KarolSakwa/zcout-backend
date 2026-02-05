<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Duel extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attribute_id',
        'player_a_id',
        'player_b_id',
    ];
}
