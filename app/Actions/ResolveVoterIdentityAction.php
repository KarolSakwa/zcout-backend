<?php

namespace App\Actions;

use App\Data\ActionFailure;
use App\Data\DuelVote\VoterIdentity;
use Illuminate\Http\Request;

final class ResolveVoterIdentityAction
{
    public function execute(Request $request): VoterIdentity|ActionFailure
    {
        $userId = auth()->id();
        $isAuthenticated = $userId !== null;
        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        $lockKeys = [];
        if ($anonId !== '') {
            $lockKeys[] = $anonId;
        }
        if ($isAuthenticated) {
            $lockKeys[] = 'user:' . $userId;
        }

        $lockKey = $anonId !== '' ? $anonId : ($isAuthenticated ? ('user:' . $userId) : null);

        if (!$lockKey) {
            return new ActionFailure(400, 'Missing voter id.');
        }

        $voterHash = hash_hmac('sha256', $lockKey, (string) config('app.key'));

        return new VoterIdentity(
            userId: $userId,
            isAuthenticated: $isAuthenticated,
            lockKeys: $lockKeys,
            lockKey: $lockKey,
            voterHash: $voterHash,
        );
    }
}
