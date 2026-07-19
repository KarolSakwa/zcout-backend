<?php

namespace App\Actions;

final class ResolveVoterContextAction
{
    public function handle(): array
    {
        $anon = request()->header('X-Zcout-Anon');
        $isAuthed = auth()->check();
        $userId = $isAuthed ? auth()->id() : null;

        $voterHash = $anon ?: ($isAuthed ? AuthenticatedVoterLockKey::forUserId((int) $userId) : null);

        if (!$voterHash) {
            return [
                'status' => 'failed',
                'failure_reason' => 'missing_voter_id',
                'anon' => null,
                'user_id' => $userId,
                'voter_hash' => null,
                'vote_voter_hash' => null,
            ];
        }

        return [
            'status' => 'ok',
            'failure_reason' => null,
            'anon' => $anon,
            'user_id' => $userId,
            'voter_hash' => $voterHash,
            'vote_voter_hash' => hash_hmac('sha256', $voterHash, (string) config('app.key')),
        ];
    }
}
