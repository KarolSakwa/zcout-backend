<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'color_primary',
        'color_secondary',
        'is_current_premier_league',
    ];

    protected $casts = [
        'is_current_premier_league' => 'boolean',
    ];

    public function players()
    {
        return $this->hasMany(Player::class, 'club_id');
    }

    public function scopeCurrentPremierLeague(Builder $query): Builder
    {
        return $query->where('is_current_premier_league', true);
    }
}
