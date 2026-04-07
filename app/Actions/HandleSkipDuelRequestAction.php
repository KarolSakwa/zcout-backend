<?php

namespace App\Actions;

final class HandleSkipDuelRequestAction
{
    public function __construct(
        private SkipDuelForVoterAction $skipDuelForVoterAction
    ) {
    }

    public function handle(array $context): array
    {
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $duelId = (int) ($context['duel_id'] ?? 0);
        $userId = $context['user_id'] ?? null;

        if ($voterHash === '') {
            return [
                'status' => 'error',
                'http_status' => 400,
                'body' => ['error' => 'Missing voter id'],
            ];
        }

        if ($duelId <= 0) {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => ['error' => 'Missing duel_id'],
            ];
        }

        $skipped = $this->skipDuelForVoterAction->handle([
            'voter_hash' => $voterHash,
            'duel_id' => $duelId,
            'user_id' => $userId,
        ]);

        if (($skipped['status'] ?? 'failed') !== 'ok') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => [
                    'error' => 'Failed to skip duel',
                    'reason' => $skipped['reason'] ?? 'failed_to_skip_duel',
                ],
            ];
        }

        return [
            'status' => 'ok',
            'payload' => [
                'ok' => true,
            ],
        ];
    }
}
