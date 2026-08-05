<?php

namespace App\Data\Scouting;

final readonly class ScoutingProgressData
{
    public function __construct(
        public int $contributions,
        public bool $myScoutingUnlocked,
        public int $progressTarget,
        public string $nextUnlock,
        public int $stageProgress,
        public int $stageTarget,
    ) {
    }

    /**
     * @return array{
     *     contributions: int,
     *     my_scouting_unlocked: bool,
     *     progress_target: int,
     *     next_unlock: string,
     *     stage_progress: int,
     *     stage_target: int
     * }
     */
    public function toArray(): array
    {
        return [
            'contributions' => $this->contributions,
            'my_scouting_unlocked' => $this->myScoutingUnlocked,
            'progress_target' => $this->progressTarget,
            'next_unlock' => $this->nextUnlock,
            'stage_progress' => $this->stageProgress,
            'stage_target' => $this->stageTarget,
        ];
    }
}
