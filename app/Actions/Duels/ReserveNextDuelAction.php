<?php

namespace App\Actions\Duels;

use Illuminate\Support\Facades\DB;

final class ReserveNextDuelAction
{
    public function handle(array $context): array
    {
        $duel = $context['duel'] ?? null;
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $skipped = $context['skipped'] ?? [];
        $voted = $context['voted'] ?? [];

        if (!$duel) {
            return [
                'status' => 'failed',
                'failure_reason' => 'missing_duel',
                'duel' => null,
            ];
        }

        $duelId = (int) ($duel->id ?? 0);

        if ($duelId <= 0) {
            return [
                'status' => 'failed',
                'failure_reason' => 'invalid_duel',
                'duel' => null,
            ];
        }

        if ($voterHash === '') {
            return [
                'status' => 'failed',
                'failure_reason' => 'missing_voter_hash',
                'duel' => null,
            ];
        }

        if (isset($skipped[$duelId])) {
            return [
                'status' => 'skipped',
                'failure_reason' => 'duel_skipped',
                'duel' => $duel,
            ];
        }

        if (isset($voted[$duelId])) {
            return [
                'status' => 'already_voted',
                'failure_reason' => 'duel_already_voted',
                'duel' => $duel,
            ];
        }

        $now = now();

        DB::table('voter_duel_locks')->upsert(
            [[
                'voter_hash' => $voterHash,
                'duel_id' => $duelId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['voter_hash'],
            ['duel_id', 'updated_at']
        );

        return [
            'status' => 'ok',
            'failure_reason' => null,
            'duel' => $duel,
        ];
    }
}
