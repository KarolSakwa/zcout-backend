<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerAttributeRatingUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $playerId,
        public string $attributeKey,
        public float $rating,
        public float $confidence,
    ) {
    }
}
