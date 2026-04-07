<?php

namespace App\Matchmaking;

use function sqrt;

final class CalibrationOpportunitySelector
{
    public function handle(array $context): array
    {
        $candidates = $context['candidates'] ?? [];
        $attributeKey = $context['attribute_key'] ?? null;
        $gaps = $context['gaps'] ?? [];
        $maxCost = (int) ($context['max_cost'] ?? 0);

        if (!is_array($candidates) || count($candidates) < 2 || !is_string($attributeKey) || $attributeKey === '') {
            return [
                'picked_a' => null,
                'picked_b' => null,
                'selected_positional_mode' => null,
                'gap' => null,
                'meta_a' => null,
                'meta_b' => null,
                'tries_used' => 0,
                'fallbacks' => [],
            ];
        }

        $pickedA = null;
        $pickedB = null;
        $metaA = null;
        $metaB = null;
        $gap = null;
        $triesUsed = 0;
        $fallbacks = [];

        $obviousMin = (float) ($gaps['obvious_min'] ?? 25);
        $maxTries = 8;

        for ($t = 0; $t < $maxTries; $t++) {
            $triesUsed = $t + 1;

            $aTry = $this->pickRandom($candidates);
            if (!$aTry) {
                break;
            }

            $aMeta = $this->expectedRatingMeta(
                $aTry['rating'] ?? null,
                $aTry['pos'] ?? null,
                $attributeKey,
                $aTry['cost'] ?? null,
                $maxCost,
            );

            $ratingA = (float) ($aMeta['value'] ?? 0.0);
            $bMatches = [];

            foreach ($candidates as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid === (int) ($aTry['id'] ?? 0)) {
                    continue;
                }

                $bMeta = $this->expectedRatingMeta(
                    $c['rating'] ?? null,
                    $c['pos'] ?? null,
                    $attributeKey,
                    $c['cost'] ?? null,
                    $maxCost,
                );

                $ratingB = (float) ($bMeta['value'] ?? 0.0);
                $currentGap = abs($ratingA - $ratingB);

                if ($currentGap >= $obviousMin) {
                    $bMatches[] = [
                        'id' => $c['id'],
                        'pos' => $c['pos'],
                        'line' => $c['line'],
                        'rep' => $c['rep'],
                        'conf' => $c['conf'],
                        'rating' => $c['rating'],
                        'cost' => $c['cost'] ?? 0,
                        'w' => $c['w'],
                        'gap' => $currentGap,
                        'meta' => $bMeta,
                    ];
                }
            }

            if (count($bMatches) > 0) {
                $pickedBTry = $this->pickRandom($bMatches);

                if ($pickedBTry) {
                    $pickedA = $aTry;
                    $pickedB = $pickedBTry;
                    $metaA = $aMeta;
                    $metaB = $pickedBTry['meta'];
                    $gap = (float) $pickedBTry['gap'];
                    break;
                }
            }
        }

        if (!$pickedA || !$pickedB) {
            $fallbacks[] = 'category_or_scope_no_match';
            $fallbacks[] = 'obvious_fallback_max_gap';

            $pickedA = $this->pickRandom($candidates);

            if ($pickedA) {
                $best = $this->pickBestGapOpponent($candidates, $pickedA, $attributeKey, $maxCost);

                if ($best) {
                    $pickedB = $best['b'];
                    $metaA = $best['metaA'];
                    $metaB = $best['metaB'];
                    $gap = (float) $best['gap'];

                    if ($gap < $obviousMin) {
                        $fallbacks[] = 'obvious_gap_relaxed';
                    }
                }
            }
        }

        return [
            'picked_a' => $pickedA,
            'picked_b' => $pickedB,
            'selected_positional_mode' => null,
            'gap' => $gap,
            'meta_a' => $metaA,
            'meta_b' => $metaB,
            'tries_used' => $triesUsed,
            'fallbacks' => $fallbacks,
        ];
    }

    private function pickRandom(array $items): ?array
    {
        if (count($items) < 1) {
            return null;
        }

        $index = array_rand($items);

        return $items[$index] ?? null;
    }

    private function pickWeighted(array $items): ?array
    {
        $total = 0.0;
        foreach ($items as $it) {
            $w = (float) ($it['w'] ?? 0.0);
            if ($w > 0) {
                $total += $w;
            }
        }

        if ($total <= 0) {
            return null;
        }

        $r = (mt_rand() / mt_getrandmax()) * $total;
        $acc = 0.0;

        foreach ($items as $it) {
            $w = (float) ($it['w'] ?? 0.0);
            if ($w <= 0) {
                continue;
            }

            $acc += $w;
            if ($r <= $acc) {
                return $it;
            }
        }

        return $items[count($items) - 1] ?? null;
    }

    private function pickBestGapOpponent(array $candidates, array $a, string $attrKey, int $maxCost): ?array
    {
        $aId = (int) ($a['id'] ?? 0);
        $metaA = $this->expectedRatingMeta($a['rating'] ?? null, $a['pos'] ?? null, $attrKey, $a['cost'] ?? null, $maxCost);
        $ratingA = (float) ($metaA['value'] ?? 0);

        $best = null;
        $bestGap = -1.0;
        $bestMetaB = null;

        foreach ($candidates as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid === $aId) {
                continue;
            }

            $metaB = $this->expectedRatingMeta($c['rating'] ?? null, $c['pos'] ?? null, $attrKey, $c['cost'] ?? null, $maxCost);
            $ratingB = (float) ($metaB['value'] ?? 0);

            $g = abs($ratingA - $ratingB);
            if ($g > $bestGap) {
                $bestGap = $g;
                $best = $c;
                $bestMetaB = $metaB;
            }
        }

        if (!$best) {
            return null;
        }

        return [
            'b' => $best,
            'metaA' => $metaA,
            'metaB' => $bestMetaB,
            'gap' => $bestGap,
        ];
    }

    private function expectedRatingMeta(?float $rating, ?string $posShort, string $attrKey, ?int $fplCost = null, ?int $maxCost = null): array
    {
        if ($rating !== null) {
            return ['value' => (float) $rating, 'source' => 'rating'];
        }

        $seedClass = 'App\\Support\\Seed';
        $seed = null;

        if ($posShort && class_exists($seedClass) && method_exists($seedClass, 'for')) {
            $v = $seedClass::for($posShort, $attrKey);
            if (is_numeric($v)) {
                $seed = (float) $v;
            }
        }

        if ($seed === null) {
            $seed = 50.0;
        }

        $val = $seed;
        $src = 'seed';

        $components = [
            'seed' => $seed,
        ];

        $mc = $maxCost !== null ? (int) $maxCost : 0;
        $fc = $fplCost !== null ? (int) $fplCost : 0;

        if ($mc > 0 && $fc > 0) {
            $delta = 30.0;
            $ratio = $fc / $mc;
            if ($ratio < 0) {
                $ratio = 0;
            }
            if ($ratio > 1) {
                $ratio = 1;
            }

            $norm = sqrt($ratio);
            $adj = ($norm - 0.5) * $delta;

            $val += $adj;
            $src = 'seed+fpl_cost';

            $components['cost'] = $fc;
            $components['max_cost'] = $mc;
            $components['cost_ratio'] = $ratio;
            $components['cost_norm'] = $norm;
            $components['cost_adj'] = $adj;
        }

        if ($val < 1) {
            $val = 1;
        }
        if ($val > 99) {
            $val = 99;
        }

        $components['value'] = $val;

        return [
            'value' => (float) $val,
            'source' => $src,
            'components' => $components,
        ];
    }
}
