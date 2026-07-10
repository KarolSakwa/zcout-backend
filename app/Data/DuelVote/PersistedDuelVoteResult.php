<?php

namespace App\Data\DuelVote;

use App\Models\Vote;

final readonly class PersistedDuelVoteResult
{
    public function __construct(
        public Vote $vote,
        public float $ratingAfterA,
        public float $ratingAfterB,
    ) {
    }
}
