<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerOverall extends Model
{
    protected $fillable = [
        'player_id',
        'position',
        'overall',
        'confidence',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
