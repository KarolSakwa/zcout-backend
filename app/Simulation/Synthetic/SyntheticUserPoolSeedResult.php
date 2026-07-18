<?php

namespace App\Simulation\Synthetic;

final class SyntheticUserPoolSeedResult
{
    /**
     * @param  list<array{index: int, reason: string}>  $conflictDetails
     */
    public function __construct(
        public readonly string $poolKey,
        public readonly int $targetCount,
        public int $existingValid = 0,
        public int $created = 0,
        public int $wouldCreate = 0,
        public int $conflicts = 0,
        public bool $poolAlreadyAboveTarget = false,
        public int $createdExpert = 0,
        public int $createdCasual = 0,
        public int $createdNoisy = 0,
        public int $wouldCreateExpert = 0,
        public int $wouldCreateCasual = 0,
        public int $wouldCreateNoisy = 0,
        public array $conflictDetails = [],
        public readonly bool $dryRun = false,
    ) {
    }

    public function recordConflict(int $index, string $reason): void
    {
        $this->conflicts++;
        $this->conflictDetails[] = [
            'index' => $index,
            'reason' => $reason,
        ];
    }

    public function recordCreatedProfile(string $decisionProfile): void
    {
        $this->created++;
        match ($decisionProfile) {
            SyntheticDecisionProfiles::EXPERT => $this->createdExpert++,
            SyntheticDecisionProfiles::CASUAL => $this->createdCasual++,
            SyntheticDecisionProfiles::NOISY => $this->createdNoisy++,
            default => null,
        };
    }

    public function recordWouldCreateProfile(string $decisionProfile): void
    {
        $this->wouldCreate++;
        match ($decisionProfile) {
            SyntheticDecisionProfiles::EXPERT => $this->wouldCreateExpert++,
            SyntheticDecisionProfiles::CASUAL => $this->wouldCreateCasual++,
            SyntheticDecisionProfiles::NOISY => $this->wouldCreateNoisy++,
            default => null,
        };
    }
}
