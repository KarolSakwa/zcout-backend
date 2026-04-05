<?php

namespace App\Matchmaking;

final class ProductionDuelPlanner
{
    public function handle(array $context): array
    {
        $candidates = $context['candidates'] ?? [];
        $attributeKey = (string) ($context['attribute_key'] ?? '');
        $gapProfile = (string) ($context['gap_profile'] ?? 'close');
        $positionProfile = (string) ($context['position_profile'] ?? 'adjacent');
        $positionalAdjacent = $context['positional_adjacent'] ?? [];
        $positionalSides = $context['positional_sides'] ?? [];
        $gaps = $context['gaps'] ?? [];
        $maxCost = (int) ($context['max_cost'] ?? 0);
        $maxSel = (float) ($context['max_sel'] ?? 0.0);

        $result = [
            'picked_a' => null,
            'picked_b' => null,
            'selected_positional_mode' => null,
            'gap' => null,
            'meta_a' => null,
            'meta_b' => null,
            'tries_used' => 0,
            'fallbacks' => [],
        ];

        if ($attributeKey === '' || count($candidates) < 2) {
            return $result;
        }

        $modesToTry = $this->positionalModesToTry($positionProfile);
        $maxTries = 8;

        for ($t = 0; $t < $maxTries; $t++) {
            $result['tries_used'] = $t + 1;

            $aTry = $this->pickWeighted($candidates);
            if (!$aTry) {
                break;
            }

            $aMeta = $this->expectedRatingMeta(
                $aTry['rating'] ?? null,
                $aTry['pos'] ?? null,
                $attributeKey,
                $aTry['cost'] ?? null,
                $maxCost,
                $aTry['sel'] ?? null,
                $maxSel
            );

            $ratingA = (float) $aMeta['value'];

            foreach ($modesToTry as $modeTry) {
                $pool = $this->filterByPositionalMode($candidates, $aTry, $modeTry, $positionalAdjacent, $positionalSides);
                if (count($pool) < 1) {
                    continue;
                }

                $bMatches = [];

                foreach ($pool as $c) {
                    $bMeta = $this->expectedRatingMeta(
                        $c['rating'] ?? null,
                        $c['pos'] ?? null,
                        $attributeKey,
                        $c['cost'] ?? null,
                        $maxCost,
                        $c['sel'] ?? null,
                        $maxSel
                    );

                    $ratingB = (float) $bMeta['value'];
                    $g = abs($ratingA - $ratingB);

                    $ok = false;

                    if ($gapProfile === 'close') {
                        $ok = $g <= (float) ($gaps['close_max'] ?? 6);
                    } elseif ($gapProfile === 'medium') {
                        $min = (float) ($gaps['medium_min'] ?? 7);
                        $max = (float) ($gaps['medium_max'] ?? 24);
                        $ok = $g >= $min && $g <= $max;
                    }

                    if ($ok) {
                        $bMatches[] = [
                            'id' => $c['id'],
                            'pos' => $c['pos'],
                            'line' => $c['line'] ?? null,
                            'rep' => $c['rep'] ?? null,
                            'conf' => $c['conf'] ?? null,
                            'rating' => $c['rating'] ?? null,
                            'cost' => $c['cost'] ?? 0,
                            'sel' => $c['sel'] ?? 0.0,
                            'w' => $c['w'] ?? 0.0,
                            'gap' => $g,
                            'meta' => $bMeta,
                        ];
                    }
                }

                if (count($bMatches) > 0) {
                    $pickedBtry = $this->pickWeighted($bMatches);

                    if ($pickedBtry) {
                        $result['picked_a'] = $aTry;
                        $result['picked_b'] = $pickedBtry;
                        $result['selected_positional_mode'] = $modeTry;
                        $result['gap'] = (float) $pickedBtry['gap'];
                        $result['meta_a'] = $aMeta;
                        $result['meta_b'] = $pickedBtry['meta'];

                        return $result;
                    }
                }
            }
        }

        if ((!$pickedA || !$pickedB) && in_array($gapProfile, ['close', 'medium'], true)) {
            $fallbacks[] = 'category_or_scope_no_match';
            $fallbacks[] = 'fallback_to_positional';

            $pair = $this->pickPositionalPair(
                $candidates,
                $attributeKey,
                $positionProfile,
                $positionalAdjacent,
                $positionalSides,
                10,
                $maxCost,
                $maxSel
            );

            if ($pair) {
                $pickedA = $pair['a'];
                $pickedB = $pair['b'];
                $selectedPositionalMode = $pair['mode'];
                $triesUsed = max($triesUsed, (int) ($pair['tries_used'] ?? 0));

                foreach (($pair['fallbacks'] ?? []) as $fallback) {
                    $fallbacks[] = $fallback;
                }

                $metaA = $this->expectedRatingMeta(
                    $pickedA['rating'] ?? null,
                    $pickedA['pos'] ?? null,
                    $attributeKey,
                    $pickedA['cost'] ?? null,
                    $maxCost,
                    $pickedA['sel'] ?? null,
                    $maxSel
                );

                $metaB = $this->expectedRatingMeta(
                    $pickedB['rating'] ?? null,
                    $pickedB['pos'] ?? null,
                    $attributeKey,
                    $pickedB['cost'] ?? null,
                    $maxCost,
                    $pickedB['sel'] ?? null,
                    $maxSel
                );

                $gap = abs(((float) $metaA['value']) - ((float) $metaB['value']));
            }
        }

        return $result;
    }

    private function pickPositionalPair(
        array  $candidates,
        string $attrKey,
        string $modeWanted,
        array  $adjacentMap,
        array  $sidesMap,
        int    $maxTries,
        int    $maxCost,
        float  $maxSel
    ): ?array
    {
        $modeChain = $this->positionalModesToTry($modeWanted);

        for ($t = 0; $t < $maxTries; $t++) {
            $a = $this->pickWeighted($candidates);
            if (!$a) return null;

            $aPos = (string)($a['pos'] ?? '');
            if ($aPos === '') continue;

            foreach ($modeChain as $mode) {
                $pool = $this->filterByPositionalMode($candidates, $a, $mode, $adjacentMap, $sidesMap);
                if (count($pool) < 1) continue;

                $b = $this->pickWeighted($pool);
                if ($b) {
                    return [
                        'a' => $a,
                        'b' => $b,
                        'mode' => $mode,
                        'tries_used' => $t + 1,
                        'fallbacks' => $mode !== $modeWanted ? ['positional_mode_stepdown'] : [],
                    ];
                }
            }
        }

        return null;
    }

    private function pickBestGapOpponent(array $candidates, array $a, string $attrKey, int $maxCost, float $maxSel): ?array
    {
        $aId = (int)($a['id'] ?? 0);
        $metaA = $this->expectedRatingMeta($a['rating'] ?? null, $a['pos'] ?? null, $attrKey, $a['cost'] ?? null, $maxCost, $a['sel'] ?? null, $maxSel);
        $ratingA = (float)($metaA['value'] ?? 0);

        $best = null;
        $bestGap = -1.0;
        $bestMetaB = null;

        foreach ($candidates as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid === $aId) continue;

            $metaB = $this->expectedRatingMeta($c['rating'] ?? null, $c['pos'] ?? null, $attrKey, $c['cost'] ?? null, $maxCost, $c['sel'] ?? null, $maxSel);
            $ratingB = (float)($metaB['value'] ?? 0);

            $g = abs($ratingA - $ratingB);
            if ($g > $bestGap) {
                $bestGap = $g;
                $best = $c;
                $bestMetaB = $metaB;
            }
        }

        if (!$best) return null;

        return [
            'b' => $best,
            'metaA' => $metaA,
            'metaB' => $bestMetaB,
            'gap' => $bestGap,
        ];
    }

    private function positionalModesToTry(string $mode): array
    {
        if ($mode === 'exact') return ['exact', 'adjacent', 'same_side', 'any'];
        if ($mode === 'adjacent') return ['adjacent', 'same_side', 'any'];
        if ($mode === 'same_side') return ['same_side', 'any'];
        return ['any'];
    }

    private function filterByPositionalMode(array $candidates, array $a, string $mode, array $adjacentMap, array $sidesMap): array
    {
        $out = [];

        $aId = (int)($a['id'] ?? 0);
        $aPos = (string)($a['pos'] ?? '');

        $side = null;
        if ($aPos !== '') {
            $def = $sidesMap['def'] ?? [];
            $off = $sidesMap['off'] ?? [];
            if (in_array($aPos, $def, true)) $side = 'def';
            if (in_array($aPos, $off, true)) $side = 'off';
        }

        $adj = [];
        if ($aPos !== '' && isset($adjacentMap[$aPos]) && is_array($adjacentMap[$aPos])) {
            $adj = array_values(array_unique(array_map(fn($x) => strtoupper(trim((string)$x)), $adjacentMap[$aPos])));
        }

        if ($mode === 'any') {
            foreach ($candidates as $c) {
                $cid = (int)($c['id'] ?? 0);
                if ($cid === $aId) continue;
                $out[] = $c;
            }
            return $out;
        }

        foreach ($candidates as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid === $aId) continue;

            $pos = (string)($c['pos'] ?? '');
            if ($pos === '') continue;

            if ($mode === 'exact') {
                if ($pos !== $aPos) continue;
            } elseif ($mode === 'adjacent') {
                if (empty($adj)) continue;
                if ($pos === $aPos) continue;
                if (!in_array($pos, $adj, true)) continue;
            } elseif ($mode === 'same_side') {
                if (!$side) continue;
                $set = $sidesMap[$side] ?? [];
                if (!is_array($set) || empty($set)) continue;
                if ($pos === $aPos) continue;
                if (!in_array($pos, $set, true)) continue;
            }

            $out[] = $c;
        }

        return $out;
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

    private function expectedRatingMeta(?float $rating, ?string $posShort, string $attrKey, ?int $fplCost = null, ?int $maxCost = null, ?float $fplSel = null, ?float $maxSel = null): array
    {
        if ($rating !== null) {
            return ['value' => (float)$rating, 'source' => 'rating'];
        }

        $seedClass = 'App\\Support\\Seed';
        $seed = null;

        if ($posShort && class_exists($seedClass) && method_exists($seedClass, 'for')) {
            $v = $seedClass::for($posShort, $attrKey);
            if (is_numeric($v)) $seed = (float)$v;
        }

        if ($seed === null) $seed = 50.0;

        $val = $seed;
        $src = 'seed';

        $components = [
            'seed' => $seed,
        ];

        $mc = $maxCost !== null ? (int)$maxCost : 0;
        $fc = $fplCost !== null ? (int)$fplCost : 0;

        if ($mc > 0 && $fc > 0) {
            $delta = (float)(config('zcout_matchmaking.rating_proxy_cost_delta') ?? 30);
            $ratio = $fc / $mc;
            if ($ratio < 0) $ratio = 0;
            if ($ratio > 1) $ratio = 1;

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

        $ms = $maxSel !== null ? (float)$maxSel : 0.0;
        $fs = $fplSel !== null ? (float)$fplSel : 0.0;

        if ($ms > 0 && $fs > 0) {
            $delta = (float)(config('zcout_matchmaking.rating_proxy_sel_delta') ?? 12);
            $ratio = $fs / $ms;
            if ($ratio < 0) $ratio = 0;
            if ($ratio > 1) $ratio = 1;

            $norm = sqrt($ratio);
            $adj = ($norm - 0.5) * $delta;

            $val += $adj;
            $src = $src === 'seed' ? 'seed+fpl_sel' : ($src . '+fpl_sel');

            $components['sel'] = $fs;
            $components['max_sel'] = $ms;
            $components['sel_ratio'] = $ratio;
            $components['sel_norm'] = $norm;
            $components['sel_adj'] = $adj;
        }

        if ($val < 1) $val = 1;
        if ($val > 99) $val = 99;

        $components['value'] = $val;

        return [
            'value' => (float)$val,
            'source' => $src,
            'components' => $components,
        ];
    }
}
