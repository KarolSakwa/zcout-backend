<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAttributeRating extends Model
{
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public $timestamps = false;

    protected $fillable = [
        'player_id',
        'attribute_id',
        'rating',
        'votes_count',
    ];
}
