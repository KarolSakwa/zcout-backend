<?php

namespace App\Data\DuelVote;

final readonly class VoterIdentity
{
    /**
     * @param  list<string>  $lockKeys
     */
    public function __construct(
        public ?int $userId,
        public bool $isAuthenticated,
        public array $lockKeys,
        public string $lockKey,
        public string $voterHash,
    ) {
    }
}
