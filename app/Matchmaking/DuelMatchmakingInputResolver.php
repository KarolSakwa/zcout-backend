<?php

namespace App\Matchmaking;

final class DuelMatchmakingInputResolver
{
    public function handle(array $cfg): array
    {
        $debug = (string) request('debug') === '1';

        $intentMix = $cfg['intent_mix'] ?? [
                'calibration' => 0.10,
                'production' => 0.90,
            ];

        $productionTierMix = $cfg['production_tier_mix'] ?? [
                'A' => 0.75,
                'B' => 0.20,
                'C' => 0.05,
            ];

        $productionPositionProfileMix = $cfg['production_position_profile_mix'] ?? [
                'exact' => 0.35,
                'adjacent' => 0.45,
                'same_side' => 0.15,
                'any' => 0.05,
            ];

        $productionGapProfileMix = $cfg['production_gap_profile_mix'] ?? [
                'close' => 0.75,
                'medium' => 0.25,
            ];

        $positionalMix = $cfg['positional_mix'] ?? [
                'exact' => 0.30,
                'adjacent' => 0.50,
                'same_side' => 0.15,
                'any' => 0.05,
            ];

        $positionalAdjacent = $cfg['positional_adjacent'] ?? [];

        $positionalSides = $cfg['positional_sides'] ?? [
                'def' => ['CB', 'LB', 'RB', 'LWB', 'RWB', 'WB', 'DM'],
                'off' => ['CM', 'AM', 'LM', 'RM', 'LW', 'RW', 'ST', 'CF'],
            ];

        $gaps = $cfg['rating_gap'] ?? [
                'close_max' => 6,
                'medium_min' => 7,
                'medium_max' => 16,
                'obvious_min' => 25,
            ];

        $needPow = (float) ($cfg['weights']['need_pow'] ?? 1.2);

        $requestedIntent = request('intent');
        $intent = in_array($requestedIntent, ['calibration', 'production'], true)
            ? $requestedIntent
            : $this->rollFromMix($intentMix, 'production', ['calibration', 'production']);

        $requestedTier = request('tier');
        $tier = null;

        if ($intent === 'production') {
            $tier = in_array($requestedTier, ['A', 'B', 'C'], true)
                ? $requestedTier
                : $this->rollFromMix($productionTierMix, 'A', ['A', 'B', 'C']);
        }

        $requestedPositionProfile = request('position_profile');
        $positionProfile = null;

        if ($intent === 'production') {
            $positionProfile = in_array($requestedPositionProfile, ['exact', 'adjacent', 'same_side', 'any'], true)
                ? $requestedPositionProfile
                : $this->rollFromMix($productionPositionProfileMix, 'adjacent', ['exact', 'adjacent', 'same_side', 'any']);
        }

        $requestedGapProfile = request('gap_profile');
        $gapProfile = null;

        if ($intent === 'production') {
            $gapProfile = in_array($requestedGapProfile, ['close', 'medium'], true)
                ? $requestedGapProfile
                : $this->rollFromMix($productionGapProfileMix, 'close', ['close', 'medium']);
        }

        return [
            'debug' => $debug,

            'intent' => $intent,
            'tier' => $tier,
            'position_profile' => $positionProfile,
            'gap_profile' => $gapProfile,

            'category' => $intent === 'calibration' ? 'obvious' : null,

            'positional_adjacent' => $positionalAdjacent,
            'positional_sides' => $positionalSides,
            'rating_gap' => $gaps,

            'need_pow' => $needPow,

            'requested' => [
                'attribute' => request('attribute'),
                'intent' => $requestedIntent,
                'tier' => $requestedTier,
                'position_profile' => $requestedPositionProfile,
                'gap_profile' => $requestedGapProfile,
            ],
            'picked' => [
                'intent' => $intent,
                'tier' => $tier,
                'position_profile' => $positionProfile,
                'gap_profile' => $gapProfile,
            ],
        ];
    }

    private function rollFromMix(array $mix, string $default, array $keys): string
    {
        $r = mt_rand() / mt_getrandmax();
        $sum = 0.0;

        foreach ($keys as $k) {
            $sum += (float) ($mix[$k] ?? 0.0);
            if ($r <= $sum) {
                return $k;
            }
        }

        return $default;
    }
}
