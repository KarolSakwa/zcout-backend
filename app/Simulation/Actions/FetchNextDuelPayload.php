<?php

namespace App\Simulation\Actions;

use App\Actions\Duels\HandleNextDuelRequestAction;

final class FetchNextDuelPayload
{
    public function __construct(
        private readonly HandleNextDuelRequestAction $handleNextDuelRequestAction,
    ) {
    }

    public function handle(?string $attributeKey = null, ?string $anonId = null, ?int $appUserId = null): ?array
    {
        $voterHash = $anonId ?: ($appUserId !== null ? ('user:' . $appUserId) : null);

        if ($voterHash === null || $voterHash === '') {
            return null;
        }

        $result = $this->handleNextDuelRequestAction->handle([
            'cfg' => config('zcout_matchmaking', []),
            'requested_attribute' => $attributeKey,
            'requested_intent' => null,
            'requested_tier' => null,
            'requested_position_profile' => null,
            'requested_gap_profile' => null,
            'debug' => false,
            'max_attempts' => 12,
            'voter_hash' => $voterHash,
            'vote_voter_hash' => hash_hmac('sha256', $voterHash, (string) config('app.key')),
        ]);

        if (($result['status'] ?? 'error') !== 'ok') {
            return null;
        }

        $payload = $result['payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }
}
