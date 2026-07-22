<?php

namespace App\Actions\Duels;

use App\Models\Attribute;

final class PlanNextDuelForVoterAction
{
    public function __construct(
        private GetNextDuelAction $getNextDuelAction,
        private MaterializeNextDuelAction $materializeNextDuelAction,
        private ReserveNextDuelAction $reserveNextDuelAction,
        private SelectNextDuelAttributeAction $selectNextDuelAttributeAction
    ) {
    }

    public function handle(array $context): array
    {
        $cfg = $context['cfg'] ?? [];
        $skipped = $context['skipped'] ?? [];
        $voted = $context['voted'] ?? [];
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $requestedAttr = $context['requested_attribute'] ?? null;
        $requestedIntent = $context['requested_intent'] ?? null;
        $requestedTier = $context['requested_tier'] ?? null;
        $requestedPositionProfile = $context['requested_position_profile'] ?? null;
        $requestedGapProfile = $context['requested_gap_profile'] ?? null;
        $debug = (bool) ($context['debug'] ?? false);
        $maxAttempts = (int) ($context['max_attempts'] ?? 12);

        if ($maxAttempts <= 0) {
            $maxAttempts = 12;
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $attribute = $this->selectNextDuelAttributeAction->handle([
                'cfg' => $cfg,
                'requested_attribute' => $requestedAttr,
            ]);

            if (!$attribute) {
                return [
                    'status' => 'failed',
                    'failure_reason' => 'unknown_attribute',
                    'attribute' => null,
                    'duel' => null,
                    'players' => null,
                    'matchmaking' => null,
                    'debug' => null,
                ];
            }

            $planned = $this->getNextDuelAction->handle([
                'attribute' => $attribute,
                'cfg' => $cfg,
                'debug' => $debug,
                'requested_attribute' => $requestedAttr,
                'requested_intent' => $requestedIntent,
                'requested_tier' => $requestedTier,
                'requested_position_profile' => $requestedPositionProfile,
                'requested_gap_profile' => $requestedGapProfile,
            ]);

            $pickedA = $planned['picked_a'] ?? null;
            $pickedB = $planned['picked_b'] ?? null;
            $fallbacks = $planned['fallbacks'] ?? [];

            if (!$pickedA || !$pickedB) {
                if (!$requestedAttr) {
                    continue;
                }

                return [
                    'status' => 'failed',
                    'failure_reason' => 'failed_to_pick_duel_pair',
                    'attribute' => $planned['attribute'] ?? $attribute,
                    'duel' => null,
                    'players' => null,
                    'matchmaking' => [
                        'category' => $planned['category'] ?? null,
                        'positional_mode' => $planned['positional_mode'] ?? null,
                        'intent' => $planned['intent'] ?? null,
                        'tier' => $planned['tier'] ?? null,
                        'gap_profile' => $planned['gap_profile'] ?? null,
                    ],
                    'debug' => $debug ? [
                        'requested' => $planned['requested'] ?? [],
                        'picked' => $planned['picked'] ?? [],
                        'fallbacks' => $fallbacks,
                        'tries_used' => (int) ($planned['tries_used'] ?? 0),
                        'attempt' => $attempt + 1,
                        'force_gk' => (bool) ($planned['force_gk'] ?? false),
                        'gap' => $planned['gap'] ?? null,
                    ] : null,
                ];
            }

            $materialized = $this->materializeNextDuelAction->handle([
                'attribute' => $planned['attribute'] ?? $attribute,
                'picked_a' => $pickedA,
                'picked_b' => $pickedB,
            ]);

            if (($materialized['status'] ?? 'failed') !== 'ok') {
                return [
                    'status' => 'failed',
                    'failure_reason' => $materialized['failure_reason'] ?? 'failed_to_materialize_duel',
                    'attribute' => $planned['attribute'] ?? $attribute,
                    'duel' => null,
                    'players' => null,
                    'matchmaking' => [
                        'category' => $planned['category'] ?? null,
                        'positional_mode' => $planned['positional_mode'] ?? null,
                        'intent' => $planned['intent'] ?? null,
                        'tier' => $planned['tier'] ?? null,
                        'gap_profile' => $planned['gap_profile'] ?? null,
                    ],
                    'debug' => $debug ? [
                        'requested' => $planned['requested'] ?? [],
                        'picked' => $planned['picked'] ?? [],
                        'fallbacks' => $fallbacks,
                        'tries_used' => (int) ($planned['tries_used'] ?? 0),
                        'attempt' => $attempt + 1,
                        'force_gk' => (bool) ($planned['force_gk'] ?? false),
                        'gap' => $planned['gap'] ?? null,
                    ] : null,
                ];
            }

            $reserved = $this->reserveNextDuelAction->handle([
                'duel' => $materialized['duel'] ?? null,
                'voter_hash' => $voterHash,
                'skipped' => $skipped,
                'voted' => $voted,
            ]);

            if (($reserved['status'] ?? 'failed') === 'skipped') {
                $fallbacks[] = 'skipped_reroll';
                continue;
            }

            if (($reserved['status'] ?? 'failed') === 'already_voted') {
                $fallbacks[] = 'already_voted_reroll';
                continue;
            }

            if (($reserved['status'] ?? 'failed') !== 'ok') {
                return [
                    'status' => 'failed',
                    'failure_reason' => $reserved['failure_reason'] ?? 'failed_to_reserve_duel',
                    'attribute' => $planned['attribute'] ?? $attribute,
                    'duel' => null,
                    'players' => null,
                    'matchmaking' => [
                        'category' => $planned['category'] ?? null,
                        'positional_mode' => $planned['positional_mode'] ?? null,
                        'intent' => $planned['intent'] ?? null,
                        'tier' => $planned['tier'] ?? null,
                        'gap_profile' => $planned['gap_profile'] ?? null,
                    ],
                    'debug' => $debug ? [
                        'requested' => $planned['requested'] ?? [],
                        'picked' => $planned['picked'] ?? [],
                        'fallbacks' => $fallbacks,
                        'tries_used' => (int) ($planned['tries_used'] ?? 0),
                        'attempt' => $attempt + 1,
                        'force_gk' => (bool) ($planned['force_gk'] ?? false),
                        'gap' => $planned['gap'] ?? null,
                    ] : null,
                ];
            }

            return [
                'status' => 'ok',
                'failure_reason' => null,
                'attribute' => $planned['attribute'] ?? $attribute,
                'duel' => $materialized['duel'] ?? null,
                'players' => $materialized['players'] ?? null,
                'matchmaking' => [
                    'category' => $planned['category'] ?? null,
                    'positional_mode' => $planned['positional_mode'] ?? null,
                    'intent' => $planned['intent'] ?? null,
                    'tier' => $planned['tier'] ?? null,
                    'gap_profile' => $planned['gap_profile'] ?? null,
                ],
                'debug' => $debug ? [
                    'requested' => $planned['requested'] ?? [],
                    'picked' => $planned['picked'] ?? [],
                    'fallbacks' => $fallbacks,
                    'tries_used' => (int) ($planned['tries_used'] ?? 0),
                    'attempt' => $attempt + 1,
                    'force_gk' => (bool) ($planned['force_gk'] ?? false),
                    'gap' => $planned['gap'] ?? null,
                ] : null,
            ];
        }

        return [
            'status' => 'failed',
            'failure_reason' => 'no_unskipped_duel_available',
            'attribute' => null,
            'duel' => null,
            'players' => null,
            'matchmaking' => null,
            'debug' => null,
        ];
    }
}
