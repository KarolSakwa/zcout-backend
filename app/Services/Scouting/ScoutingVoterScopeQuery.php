<?php

namespace App\Services\Scouting;

use App\Data\Scouting\ScoutingVoterScope;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Builder;

final class ScoutingVoterScopeQuery
{
    public function votes(ScoutingVoterScope $scope): Builder
    {
        return Vote::query()
            ->whereIn('source', ['duel', 'direct'])
            ->where(function (Builder $query) use ($scope): void {
                $hasCondition = false;

                if ($scope->userId !== null) {
                    $query->where('user_id', $scope->userId);
                    $hasCondition = true;
                }

                if ($scope->anonVoterHash !== null) {
                    $anonScope = function (Builder $inner) use ($scope): void {
                        $inner
                            ->where('voter_hash', $scope->anonVoterHash)
                            ->whereNull('user_id');
                    };

                    if ($hasCondition) {
                        $query->orWhere($anonScope);
                    } else {
                        $query->where($anonScope);
                    }
                }
            });
    }
}
