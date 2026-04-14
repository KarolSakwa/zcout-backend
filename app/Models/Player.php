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
        'fd_position_id',
        'manual_position_id',
    ];

    protected $appends = [
        'effective_name',
        'effective_number',
        'effective_position_id',
        'effective_position_short',
        'effective_position_key',
        'effective_position_label',
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

    public function fdPositionRef(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'fd_position_id', 'id');
    }

    public function manualPositionRef(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'manual_position_id', 'id');
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

    protected function effectivePositionId(): Attribute
    {
        return Attribute::get(fn () => $this->manual_position_id ?? $this->fd_position_id ?? $this->position_id);
    }

    protected function effectivePositionShort(): Attribute
    {
        return Attribute::get(fn () => $this->manualPositionRef?->short_label
            ?? $this->fdPositionRef?->short_label
            ?? $this->positionRef?->short_label);
    }

    protected function effectivePositionKey(): Attribute
    {
        return Attribute::get(fn () => $this->manualPositionRef?->key
            ?? $this->fdPositionRef?->key
            ?? $this->positionRef?->key);
    }

    protected function effectivePositionLabel(): Attribute
    {
        return Attribute::get(fn () => $this->manualPositionRef?->label
            ?? $this->fdPositionRef?->label
            ?? $this->positionRef?->label);
    }
}
