<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerArchetype extends Model
{
    protected $fillable = [
        'player_id',
        'language',
        'label',
        'fingerprint_hash',
        'fingerprint_payload',
        'input_snapshot',
        'prompt_version',
        'model',
        'generated_at',
        'last_error',
    ];

    protected $casts = [
        'fingerprint_payload' => 'array',
        'input_snapshot' => 'array',
        'generated_at' => 'datetime',
    ];
}
