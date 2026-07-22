<?php

namespace App\Actions\Duels;

use Illuminate\Support\Facades\DB;

final class SkipDuelForVoterAction
{
    public function handle(array $context): array
    {
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $duelId = (int) ($context['duel_id'] ?? 0);
        $userId = $context['user_id'] ?? null;

        if ($voterHash === '') {
            return [
                'status' => 'failed',
                'reason' => 'missing_voter_id',
            ];
        }

        if ($duelId <= 0) {
            return [
                'status' => 'failed',
                'reason' => 'missing_duel_id',
            ];
        }

        DB::table('duel_skips')->updateOrInsert(
            [
                'duel_id' => $duelId,
                'voter_hash' => $voterHash,
            ],
            [
                'user_id' => $userId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('voter_duel_locks')
            ->where('voter_hash', $voterHash)
            ->delete();

        return [
            'status' => 'ok',
            'ok' => true,
        ];
    }
}
