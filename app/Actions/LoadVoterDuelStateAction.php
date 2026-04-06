<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

final class LoadVoterDuelStateAction
{
    public function handle(array $context): array
    {
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $voteVoterHash = (string) ($context['vote_voter_hash'] ?? '');

        if ($voterHash === '' || $voteVoterHash === '') {
            return [
                'status' => 'failed',
                'skipped' => [],
                'voted' => [],
            ];
        }

        $skippedIds = DB::table('duel_skips')
            ->where('voter_hash', $voterHash)
            ->pluck('duel_id')
            ->all();

        $skipped = [];
        foreach ($skippedIds as $id) {
            $skipped[(int) $id] = true;
        }

        $votedIds = DB::table('votes')
            ->where('source', 'duel')
            ->where('voter_hash', $voteVoterHash)
            ->pluck('duel_id')
            ->all();

        $voted = [];
        foreach ($votedIds as $id) {
            $voted[(int) $id] = true;
        }

        return [
            'status' => 'ok',
            'skipped' => $skipped,
            'voted' => $voted,
        ];
    }
}
