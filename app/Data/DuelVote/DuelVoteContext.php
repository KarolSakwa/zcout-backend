<?php

namespace App\Data\DuelVote;

use App\Models\Attribute;
use App\Models\Duel;

final readonly class DuelVoteContext
{
    public function __construct(
        public Attribute $attribute,
        public Duel $duel,
        public int $winnerId,
        public int $loserId,
        public int $canonicalPlayerAId,
        public int $canonicalPlayerBId,
        public int $duelPlayerAId,
        public int $duelPlayerBId,
        public float $ratingBeforeA,
        public float $ratingBeforeB,
    ) {
    }
}
