<?php

namespace App\Data\Scouting;

final readonly class ScoutingVoterScope
{
    public function __construct(
        public ?int $userId,
        public ?string $anonVoterHash,
    ) {
    }

    public function hasIdentity(): bool
    {
        return $this->userId !== null || $this->anonVoterHash !== null;
    }
}
