<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'fd_name',
        'fd_number',
        'fd_synced_at',
        'manual_display_name',
        'manual_number',
    ];

    protected $appends = [
        'effective_name',
        'effective_number',
    ];

    public function clubRel(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'club_id', 'id');
    }

    public function countryRef(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    public function positionRef(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id', 'id');
    }

    protected static function booted()
    {
        static::creating(function ($player) {
            if (empty($player->slug)) {
                $player->slug = Str::slug($player->name);
            }
        });
    }

    protected function effectiveName(): Attribute
    {
        return Attribute::get(fn () => $this->manual_display_name ?: ($this->fd_name ?: $this->name));
    }

    protected function effectiveNumber(): Attribute
    {
        return Attribute::get(fn () => $this->manual_number ?? $this->fd_number ?? $this->number);
    }
}
