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
        'rating_weight_sum',
        'confidence_weight_sum',
        'confidence',
        'votes_count',
        'last_vote_at',
    ];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

    protected function casts(): array
    {
        return [
            'player_id' => 'integer',
            'attribute_id' => 'integer',
            'rating' => 'decimal:4',
            'rating_weight_sum' => 'decimal:4',
            'confidence_weight_sum' => 'decimal:4',
            'confidence' => 'decimal:2',
            'votes_count' => 'integer',
            'last_vote_at' => 'datetime',
        ];
    }
}
