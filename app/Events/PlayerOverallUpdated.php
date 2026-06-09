<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerOverallUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $playerId,
        public float $overall,
        public float $confidence,
    ) {
    }
}
