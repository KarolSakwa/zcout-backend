<?php

namespace App\Services\Scouting;

use App\Data\Scouting\ScoutingProgressData;
use App\Data\Scouting\ScoutingVoterScope;
use InvalidArgumentException;

final class ScoutingProgressService
{
    public function __construct(
        private readonly ScoutingVoterScopeQuery $scoutingVoterScopeQuery,
    ) {
    }

    public function countContributions(ScoutingVoterScope $scope): int
    {
        return $this->scoutingVoterScopeQuery->votes($scope)->count();
    }

    /**
     * @return array{my_scouting_unlock: int, your_impact_unlock: int, stage2_length: int}
     */
    private function thresholds(): array
    {
        $myScoutingUnlock = (int) config('scouting.my_scouting_unlock', 25);
        $yourImpactUnlock = (int) config('scouting.your_impact_unlock', 125);

        if ($myScoutingUnlock <= 0) {
            throw new InvalidArgumentException('scouting.my_scouting_unlock must be greater than 0.');
        }

        if ($yourImpactUnlock <= $myScoutingUnlock) {
            throw new InvalidArgumentException('scouting.your_impact_unlock must be greater than scouting.my_scouting_unlock.');
        }

        return [
            'my_scouting_unlock' => $myScoutingUnlock,
            'your_impact_unlock' => $yourImpactUnlock,
            'stage2_length' => $yourImpactUnlock - $myScoutingUnlock,
        ];
    }

    public function buildProgress(ScoutingVoterScope $scope): ScoutingProgressData
    {
        $contributions = $this->countContributions($scope);
        $thresholds = $this->thresholds();
        $myScoutingUnlock = $thresholds['my_scouting_unlock'];
        $stage2Length = $thresholds['stage2_length'];
        $unlocked = $contributions >= $myScoutingUnlock;

        if (! $unlocked) {
            $stageProgress = $contributions;
            $stageTarget = $myScoutingUnlock;
            $nextUnlock = 'my_scouting';
        } else {
            $stageProgress = min(max(0, $contributions - $myScoutingUnlock), $stage2Length);
            $stageTarget = $stage2Length;
            $nextUnlock = 'your_impact';
        }

        return new ScoutingProgressData(
            contributions: $contributions,
            myScoutingUnlocked: $unlocked,
            progressTarget: $stageTarget,
            nextUnlock: $nextUnlock,
            stageProgress: $stageProgress,
            stageTarget: $stageTarget,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function progressArray(ScoutingVoterScope $scope): array
    {
        return $this->buildProgress($scope)->toArray();
    }
}
