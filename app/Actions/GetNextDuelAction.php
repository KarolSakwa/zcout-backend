<?php

namespace App\Actions;

use App\Matchmaking\CalibrationOpportunitySelector;
use App\Matchmaking\DuelMatchmakingInputResolver;
use App\Matchmaking\MatchmakingCandidatePoolBuilder;
use App\Matchmaking\MatchmakingCandidateRowFetcher;
use App\Matchmaking\ProductionDuelPlanner;

final class GetNextDuelAction
{
    public function __construct(
        private DuelMatchmakingInputResolver $matchmakingInputResolver,
        private MatchmakingCandidateRowFetcher $candidateRowFetcher,
        private MatchmakingCandidatePoolBuilder $candidatePoolBuilder,
        private ProductionDuelPlanner $productionDuelPlanner,
        private CalibrationOpportunitySelector $calibrationOpportunitySelector
    ) {
    }

    public function handle(array $context): array
    {
        $attribute = $context['attribute'] ?? null;
        $cfg = $context['cfg'] ?? [];

        if (!$attribute) {
            return $this->defaultResult(null, [], false);
        }

        $forceGK = (($attribute->scope ?? 'both') === 'gk');
        $mm = $this->matchmakingInputResolver->handle($cfg);

        $intent = $mm['intent'] ?? null;
        $selectedTier = $mm['tier'] ?? null;
        $gapProfile = $mm['gap_profile'] ?? 'close';
        $positionProfile = $mm['position_profile'] ?? 'adjacent';
        $positionalAdjacent = $mm['positional_adjacent'] ?? [];
        $positionalSides = $mm['positional_sides'] ?? [];
        $gaps = $mm['rating_gap'] ?? [];
        $needPow = (float) ($mm['need_pow'] ?? 1.0);

        $result = $this->defaultResult($attribute, $mm, $forceGK);

        $rows = $this->candidateRowFetcher->handle([
            'attribute_id' => $attribute->id,
            'intent' => $intent,
            'selected_tier' => $selectedTier,
            'force_gk' => $forceGK,
        ]);

        if ($rows->count() < 2) {
            return $result;
        }

        $pool = $this->candidatePoolBuilder->handle([
            'rows' => $rows,
            'need_pow' => $needPow,
        ]);

        $candidates = $pool['candidates'] ?? [];
        $maxCost = (int) ($pool['max_cost'] ?? 0);
        $maxSel = (float) ($pool['max_sel'] ?? 0.0);

        if (count($candidates) < 2) {
            return $result;
        }

        $baseCandidates = $candidates;

        if (!$forceGK && $intent === 'calibration') {
            $tmp = [];
            foreach ($candidates as $c) {
                if (($c['pos'] ?? null) !== 'GK') {
                    $tmp[] = $c;
                }
            }

            if (count($tmp) >= 2) {
                $baseCandidates = $tmp;
            }
        }

        $planned = null;

        if ($intent === 'production') {
            $planned = $this->productionDuelPlanner->handle([
                'candidates' => $baseCandidates,
                'attribute_key' => $attribute->key,
                'gap_profile' => $gapProfile,
                'position_profile' => $positionProfile,
                'positional_adjacent' => $positionalAdjacent,
                'positional_sides' => $positionalSides,
                'gaps' => $gaps,
                'max_cost' => $maxCost,
                'max_sel' => $maxSel,
            ]);
        }

        if ($intent === 'calibration') {
            $planned = $this->calibrationOpportunitySelector->handle([
                'candidates' => $baseCandidates,
                'attribute_key' => $attribute->key,
                'gaps' => $gaps,
                'max_cost' => $maxCost,
                'max_sel' => $maxSel,
            ]);
        }

        if (!is_array($planned)) {
            return $result;
        }

        return array_merge($result, [
            'picked_a' => $planned['picked_a'] ?? null,
            'picked_b' => $planned['picked_b'] ?? null,
            'positional_mode' => $planned['selected_positional_mode'] ?? null,
            'gap' => $planned['gap'] ?? null,
            'meta_a' => $planned['meta_a'] ?? null,
            'meta_b' => $planned['meta_b'] ?? null,
            'tries_used' => (int) ($planned['tries_used'] ?? 0),
            'fallbacks' => $planned['fallbacks'] ?? [],
        ]);
    }

    private function defaultResult($attribute, array $mm, bool $forceGK): array
    {
        $intent = $mm['intent'] ?? null;

        return [
            'attribute' => $attribute,
            'picked_a' => null,
            'picked_b' => null,
            'category' => $intent === 'calibration' ? ($mm['category'] ?? null) : null,
            'intent' => $intent,
            'tier' => $mm['tier'] ?? null,
            'gap_profile' => $intent === 'production' ? ($mm['gap_profile'] ?? null) : null,
            'positional_mode' => null,
            'gap' => null,
            'meta_a' => null,
            'meta_b' => null,
            'tries_used' => 0,
            'fallbacks' => [],
            'force_gk' => $forceGK,
            'requested' => $mm['requested'] ?? [],
            'picked' => $mm['picked'] ?? [],
        ];
    }
}
