<?php

namespace App\Actions\Scouting;

use App\Data\ActionFailure;
use App\Data\Scouting\ScoutingVoterScope;
use Illuminate\Http\Request;

final class ResolveScoutingVoterScopeAction
{
    public function execute(Request $request): ScoutingVoterScope|ActionFailure
    {
        $userId = auth()->id();
        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        $anonVoterHash = $anonId !== ''
            ? hash_hmac('sha256', $anonId, (string) config('app.key'))
            : null;

        $scope = new ScoutingVoterScope(
            userId: $userId !== null ? (int) $userId : null,
            anonVoterHash: $anonVoterHash,
        );

        if (! $scope->hasIdentity()) {
            return new ActionFailure(422, 'Missing voter identity. Provide X-Zcout-Anon or authenticate.');
        }

        return $scope;
    }
}
