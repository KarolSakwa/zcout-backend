<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Player extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'country',
        'club',
        'position',
        'club_id',
    ];

    public function clubRel()
    {
        return $this->belongsTo(\App\Models\Club::class, 'club_id');
    }

    protected static function booted()
    {
        static::creating(function ($player) {
            if (empty($player->slug)) {
                $player->slug = Str::slug($player->name);
            }
        });
    }
}
