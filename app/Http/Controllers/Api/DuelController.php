<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use App\Matchmaking\DuelMatchmakingInputResolver;

class DuelController extends Controller
{
    private DuelMatchmakingInputResolver $matchmakingInputResolver;

    public function __construct(DuelMatchmakingInputResolver $matchmakingInputResolver)
    {
        $this->matchmakingInputResolver = $matchmakingInputResolver;
    }

    public function next()
    {
        $anon = request()->header('X-Zcout-Anon');
        $voterHash = $anon ?: (auth()->check() ? ('user:' . auth()->id()) : null);

        if (!$voterHash) {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $voteVoterHash = hash_hmac('sha256', $voterHash, (string) config('app.key'));

        $lockedDuelId = DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->value('duel_id');

        if ($lockedDuelId) {
            $isLockedSkipped = DB::table('duel_skips')
                ->where('duel_id', $lockedDuelId)
                ->where('voter_hash', $voterHash)
                ->exists();

            $isLockedVoted = DB::table('votes')
                ->where('source', 'duel')
                ->where('duel_id', $lockedDuelId)
                ->where('voter_hash', $voteVoterHash)
                ->exists();

            if (!$isLockedSkipped && !$isLockedVoted) {
                $lockedDuel = Duel::query()->find($lockedDuelId);

                if ($lockedDuel) {
                    $lockedAttr = Attribute::query()->find($lockedDuel->attribute_id);

                    if ($lockedAttr) {
                        $playerIds = [(int) $lockedDuel->player_a_id, (int) $lockedDuel->player_b_id];

                        $players = Player::query()
                            ->select(['id', 'name', 'slug', 'number', 'club_id', 'country_id', 'position_id'])
                            ->with([
                                'clubRel:id,name,color_primary,color_secondary,color_tertiary',
                                'countryRef:id,name,iso2',
                                'positionRef:id,short_label,label,key',
                            ])
                            ->whereIn('id', $playerIds)
                            ->get()
                            ->keyBy('id');

                        $pA = $players->get((int) $lockedDuel->player_a_id);
                        $pB = $players->get((int) $lockedDuel->player_b_id);

                        if ($pA && $pB) {
                            $toApi = function (Player $p) {
                                return [
                                    'id' => $p->id,
                                    'name' => $p->name,
                                    'slug' => $p->slug,
                                    'number' => $p->number,
                                    'position' => $p->positionRef?->short_label
                                        ?? $p->positionRef?->key
                                        ?? $p->positionRef?->label
                                        ?? null,
                                    'country' => $p->countryRef ? [
                                        'id' => $p->countryRef->id,
                                        'name' => $p->countryRef->name,
                                        'iso2' => $p->countryRef->iso2,
                                    ] : null,
                                    'club' => $p->clubRel ? [
                                        'name' => $p->clubRel->name,
                                        'color_primary' => $p->clubRel->color_primary,
                                        'color_secondary' => $p->clubRel->color_secondary,
                                        'color_tertiary' => $p->clubRel->color_tertiary,
                                    ] : null,
                                ];
                            };

                            return response()->json([
                                'attribute' => [
                                    'id' => $lockedAttr->id,
                                    'key' => $lockedAttr->key,
                                    'label' => $lockedAttr->label,
                                    'group' => $lockedAttr->group,
                                    'scope' => $lockedAttr->scope ?? 'both',
                                ],
                                'players' => [$toApi($pA), $toApi($pB)],
                                'duel_id' => $lockedDuel->id,
                                'matchmaking' => [
                                    'category' => 'locked',
                                    'pool' => null,
                                    'position_scope' => null,
                                    'positional_mode' => null,
                                    'intent' => null,
                                ],
                            ]);
                        }
                    }
                }
            }

            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();
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

        $maxAttempts = 12;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $requestedAttr = request('attribute');

            $attribute = $requestedAttr
                ? Attribute::where('key', $requestedAttr)->first()
                : Attribute::query()->where('scope', 'both')->inRandomOrder()->first();

            if (!$attribute) {
                return response()->json(['error' => 'Unknown attribute'], 422);
            }

            $forceGK = ($attribute->scope ?? 'both') === 'gk';

            $cfg = config('zcout_matchmaking', []);
            $mm = $this->matchmakingInputResolver->handle($cfg);

            $debug = $mm['debug'];

            $category = $mm['category'];
            $intent = $mm['intent'];
            $selectedTier = $mm['tier'];
            $positionProfile = $mm['position_profile'] ?? 'adjacent';
            $positionScope = $mm['position_scope'];
            $positionalMode = $mm['positional_mode'];

            $positionalAdjacent = $mm['positional_adjacent'];
            $positionalSides = $mm['positional_sides'];
            $gaps = $mm['rating_gap'];

            $needPow = $mm['need_pow'];

            $requested = $mm['requested'];
            $picked = $mm['picked'];

            $fallbacks = [];
            if ($forceGK && $category === 'positional') {
                $category = 'close';
                $fallbacks[] = 'gk_attr_forced_close';
            }

            $rowsQ = DB::table('players as p')
                ->join('player_reputation_stats as prs', 'prs.player_id', '=', 'p.id')
                ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
                ->leftJoin('player_attribute_ratings as par', function ($join) use ($attribute) {
                    $join->on('par.player_id', '=', 'p.id')
                        ->where('par.attribute_id', '=', $attribute->id);
                })
                ->whereNotNull('p.position_id');

            if ($intent === 'production' && $selectedTier !== null) {
                $rowsQ->where('prs.tier', '=', $selectedTier);
            }

            if ($forceGK) {
                $rowsQ->where('pos.short_label', '=', 'GK');
            }

            $rows = $rowsQ
                ->selectRaw('p.id, pos.short_label as pos_short, prs.player_rep, (COALESCE(par.confidence, 0) / 100.0) as attr_confidence, par.rating as attr_rating, COALESCE(prs.fpl_now_cost, 0) as fpl_cost, COALESCE(prs.fpl_selected_by_percent, 0) as fpl_sel')
                ->get();

            if ($rows->count() < 2) {
                return response()->json(['error' => 'Not enough players in pool'], 422);
            }

            $candidates = [];
            foreach ($rows as $r) {
                $posShort = $r->pos_short ? (string) $r->pos_short : null;
                if (!$posShort) continue;

                $rep = (float) $r->player_rep;
                $conf = (float) $r->attr_confidence;

                $need = 1.0 - $conf;
                if ($need < 0) $need = 0.0;
                if ($need > 1) $need = 1.0;

                $w = pow(max($need, 0.000001), $needPow);

                if ($w > 0) {
                    $candidates[] = [
                        'id' => (int) $r->id,
                        'pos' => $posShort,
                        'line' => $this->posLine($posShort),
                        'rep' => $rep,
                        'conf' => $conf,
                        'rating' => $r->attr_rating !== null ? (float) $r->attr_rating : null,
                        'cost' => (int) ($r->fpl_cost ?? 0),
                        'sel' => (float) ($r->fpl_sel ?? 0),
                        'w' => (float) $w,
                    ];
                }
            }

            if (count($candidates) < 2) {
                return response()->json(['error' => 'Not enough weighted candidates'], 422);
            }

            $maxCost = 0;
            $maxSel = 0.0;

            foreach ($candidates as $c) {
                $cc = (int) ($c['cost'] ?? 0);
                if ($cc > $maxCost) $maxCost = $cc;

                $ss = (float) ($c['sel'] ?? 0);
                if ($ss > $maxSel) $maxSel = $ss;
            }

            $triesUsed = 0;

            $pickedA = null;
            $pickedB = null;

            $selectedCategory = $category;
            $selectedScope = $positionScope;
            $selectedPositionalMode = $positionalMode;

            $metaA = null;
            $metaB = null;
            $gap = null;

            $baseCandidates = $candidates;

            if (!$forceGK && $category === 'obvious') {
                $tmp = [];
                foreach ($candidates as $c) {
                    if (($c['pos'] ?? null) !== 'GK') $tmp[] = $c;
                }
                if (count($tmp) >= 2) $baseCandidates = $tmp;
            }

            if ($category === 'positional') {
                $pair = $this->pickPositionalPair(
                    $candidates,
                    $attribute->key,
                    $positionalMode,
                    $positionalAdjacent,
                    $positionalSides,
                    10,
                    $maxCost,
                    $maxSel
                );

                if (!$pair) {
                    return response()->json(['error' => 'Failed to pick positional pair'], 422);
                }

                $pickedA = $pair['a'];
                $pickedB = $pair['b'];
                $selectedPositionalMode = $pair['mode'];
                $triesUsed = $pair['tries_used'];

                if (!empty($pair['fallbacks'])) {
                    foreach ($pair['fallbacks'] as $f) $fallbacks[] = $f;
                }

                $metaA = $this->expectedRatingMeta($pickedA['rating'], $pickedA['pos'], $attribute->key, $pickedA['cost'] ?? null, $maxCost, $pickedA['sel'] ?? null, $maxSel);
                $metaB = $this->expectedRatingMeta($pickedB['rating'], $pickedB['pos'], $attribute->key, $pickedB['cost'] ?? null, $maxCost, $pickedB['sel'] ?? null, $maxSel);
                $gap = abs(((float) $metaA['value']) - ((float) $metaB['value']));
            } else {
                $modesToTry = $this->positionalModesToTry($positionProfile);
                $maxTries = 8;

                for ($t = 0; $t < $maxTries; $t++) {
                    $triesUsed = $t + 1;

                    $aTry = $this->pickWeighted($baseCandidates);
                    if (!$aTry) break;

                    $aMeta = $this->expectedRatingMeta($aTry['rating'], $aTry['pos'], $attribute->key, $aTry['cost'] ?? null, $maxCost, $aTry['sel'] ?? null, $maxSel);
                    $ratingA = (float) $aMeta['value'];

                    foreach ($modesToTry as $modeTry) {
                        $pool = $this->filterByPositionalMode($baseCandidates, $aTry, $modeTry, $positionalAdjacent, $positionalSides);
                        if (count($pool) < 1) continue;

                        $bMatches = [];
                        foreach ($pool as $c) {
                            $bMeta = $this->expectedRatingMeta($c['rating'], $c['pos'], $attribute->key, $c['cost'] ?? null, $maxCost, $c['sel'] ?? null, $maxSel);
                            $ratingB = (float) $bMeta['value'];

                            $g = abs($ratingA - $ratingB);

                            $ok = false;

                            if ($category === 'close') {
                                $ok = $g <= (float) ($gaps['close_max'] ?? 6);
                            } elseif ($category === 'medium') {
                                $min = (float) ($gaps['medium_min'] ?? 7);
                                $max = (float) ($gaps['medium_max'] ?? 16);
                                $ok = $g >= $min && $g <= $max;
                            } else {
                                $min = (float) ($gaps['obvious_min'] ?? 25);
                                $ok = $g >= $min;
                            }

                            if ($ok) {
                                $bMatches[] = [
                                    'id' => $c['id'],
                                    'pos' => $c['pos'],
                                    'line' => $c['line'],
                                    'rep' => $c['rep'],
                                    'conf' => $c['conf'],
                                    'rating' => $c['rating'],
                                    'cost' => $c['cost'] ?? 0,
                                    'sel' => $c['sel'] ?? 0.0,
                                    'w' => $c['w'],
                                    'gap' => $g,
                                    'meta' => $bMeta,
                                ];
                            }
                        }

                        if (count($bMatches) > 0) {
                            $pickedBtry = $this->pickWeighted($bMatches);
                            if ($pickedBtry) {
                                $pickedA = $aTry;
                                $pickedB = $pickedBtry;

                                $metaA = $aMeta;
                                $metaB = $pickedBtry['meta'];
                                $gap = (float) $pickedBtry['gap'];

                                $selectedPositionalMode = $modeTry;
                                break 2;
                            }
                        }
                    }
                }

                if (!$pickedA || !$pickedB) {
                    $fallbacks[] = 'category_or_scope_no_match';

                    if (in_array($category, ['close', 'medium'], true)) {
                        $fallbacks[] = 'fallback_to_positional';
                        $selectedCategory = 'positional';

                        $pair = $this->pickPositionalPair(
                            $candidates,
                            $attribute->key,
                            $positionalMode,
                            $positionalAdjacent,
                            $positionalSides,
                            10,
                            $maxCost,
                            $maxSel
                        );

                        if (!$pair) {
                            return response()->json(['error' => 'Failed to pick positional fallback'], 422);
                        }

                        $pickedA = $pair['a'];
                        $pickedB = $pair['b'];
                        $selectedPositionalMode = $pair['mode'];
                        $triesUsed = max($triesUsed, $pair['tries_used']);

                        if (!empty($pair['fallbacks'])) {
                            foreach ($pair['fallbacks'] as $f) $fallbacks[] = $f;
                        }

                        $metaA = $this->expectedRatingMeta($pickedA['rating'], $pickedA['pos'], $attribute->key, $pickedA['cost'] ?? null, $maxCost, $pickedA['sel'] ?? null, $maxSel);
                        $metaB = $this->expectedRatingMeta($pickedB['rating'], $pickedB['pos'], $attribute->key, $pickedB['cost'] ?? null, $maxCost, $pickedB['sel'] ?? null, $maxSel);
                        $gap = abs(((float) $metaA['value']) - ((float) $metaB['value']));
                    } else {
                        $fallbacks[] = 'obvious_fallback_max_gap';

                        $pickedA = $this->pickWeighted($baseCandidates);
                        if (!$pickedA) {
                            return response()->json(['error' => 'Failed to pick player A'], 422);
                        }

                        $best = $this->pickBestGapOpponent($baseCandidates, $pickedA, $attribute->key, $maxCost, $maxSel);
                        if (!$best) {
                            return response()->json(['error' => 'Failed to pick player B'], 422);
                        }

                        $pickedB = $best['b'];
                        $metaA = $best['metaA'];
                        $metaB = $best['metaB'];
                        $gap = (float) $best['gap'];
                        $selectedScope = 'any';

                        $min = (float) ($gaps['obvious_min'] ?? 25);
                        if ($gap < $min) {
                            $fallbacks[] = 'obvious_gap_relaxed';
                        }
                    }
                }

                if ($selectedCategory !== 'positional' && $selectedScope !== $positionScope) {
                    $fallbacks[] = 'scope_relaxed';
                }
            }

            $playerIds = [(int) $pickedA['id'], (int) $pickedB['id']];

            $players = Player::query()
                ->select(['id', 'name', 'slug', 'number', 'club_id', 'country_id', 'position_id'])
                ->with([
                    'clubRel:id,name,color_primary,color_secondary,color_tertiary',
                    'countryRef:id,name,iso2',
                    'positionRef:id,short_label,label,key',
                ])
                ->whereIn('id', $playerIds)
                ->get()
                ->keyBy('id');

            if ($players->count() < 2) {
                return response()->json(['error' => 'Players not found'], 422);
            }

            $pA = $players[(int) $pickedA['id']];
            $pB = $players[(int) $pickedB['id']];

            $playerAId = min($pA->id, $pB->id);
            $playerBId = max($pA->id, $pB->id);

            $duel = Duel::firstOrCreate([
                'attribute_id' => $attribute->id,
                'player_a_id'  => $playerAId,
                'player_b_id'  => $playerBId,
            ]);

            if (isset($skipped[(int) $duel->id])) {
                $fallbacks[] = 'skipped_reroll';
                continue;
            }

            if (isset($voted[(int) $duel->id])) {
                $fallbacks[] = 'already_voted_reroll';
                continue;
            }

            $now = now();

            DB::table('voter_duel_locks')->upsert(
                [[
                    'voter_hash' => $voterHash,
                    'duel_id' => $duel->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['voter_hash'],
                ['duel_id', 'updated_at']
            );

            $toApi = function (Player $p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'number' => $p->number,
                    'position' => $p->positionRef?->short_label
                        ?? $p->positionRef?->key
                        ?? $p->positionRef?->label
                        ?? null,
                    'country' => $p->countryRef ? [
                        'id' => $p->countryRef->id,
                        'name' => $p->countryRef->name,
                        'iso2' => $p->countryRef->iso2,
                    ] : null,
                    'club' => $p->clubRel ? [
                        'name' => $p->clubRel->name,
                        'color_primary' => $p->clubRel->color_primary,
                        'color_secondary' => $p->clubRel->color_secondary,
                        'color_tertiary' => $p->clubRel->color_tertiary,
                    ] : null,
                ];
            };

            $payload = [
                'attribute' => [
                    'id' => $attribute->id,
                    'key' => $attribute->key,
                    'label' => $attribute->label,
                    'group' => $attribute->group,
                    'scope' => $attribute->scope ?? 'both',
                ],
                'players' => [$toApi($pA), $toApi($pB)],
                'duel_id' => $duel->id,
                'matchmaking' => [
                    'category' => $selectedCategory,
                    'pool' => null,
                    'position_scope' => null,
                    'positional_mode' => $intent === 'production' ? $selectedPositionalMode : ($selectedCategory === 'positional' ? $selectedPositionalMode : null),
                    'intent' => $mm['intent'],
                    'tier' => $mm['tier'],
                    'position_profile' => $mm['position_profile'],
                    'gap_profile' => in_array($selectedCategory, ['close', 'medium', 'obvious'], true) ? $selectedCategory : null,
                ],
            ];

            if ($debug) {
                $payload['debug'] = [
                    'requested' => $requested,
                    'picked' => $picked,
                    'fallbacks' => $fallbacks,
                    'tries_used' => $triesUsed,
                    'attempt' => $attempt + 1,
                    'force_gk' => $forceGK,
                ];
            }

            return response()->json($payload);
        }

        return response()->json(['error' => 'No unskipped duel available'], 422);
    }

    private function pickPositionalPair(
        array $candidates,
        string $attrKey,
        string $modeWanted,
        array $adjacentMap,
        array $sidesMap,
        int $maxTries,
        int $maxCost,
        float $maxSel
    ): ?array {
        $modeChain = $this->positionalModesToTry($modeWanted);

        for ($t = 0; $t < $maxTries; $t++) {
            $a = $this->pickWeighted($candidates);
            if (!$a) return null;

            $aPos = (string) ($a['pos'] ?? '');
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
        $aId = (int) ($a['id'] ?? 0);
        $metaA = $this->expectedRatingMeta($a['rating'] ?? null, $a['pos'] ?? null, $attrKey, $a['cost'] ?? null, $maxCost, $a['sel'] ?? null, $maxSel);
        $ratingA = (float) ($metaA['value'] ?? 0);

        $best = null;
        $bestGap = -1.0;
        $bestMetaB = null;

        foreach ($candidates as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid === $aId) continue;

            $metaB = $this->expectedRatingMeta($c['rating'] ?? null, $c['pos'] ?? null, $attrKey, $c['cost'] ?? null, $maxCost, $c['sel'] ?? null, $maxSel);
            $ratingB = (float) ($metaB['value'] ?? 0);

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

        $aId = (int) ($a['id'] ?? 0);
        $aPos = (string) ($a['pos'] ?? '');

        $side = null;
        if ($aPos !== '') {
            $def = $sidesMap['def'] ?? [];
            $off = $sidesMap['off'] ?? [];
            if (in_array($aPos, $def, true)) $side = 'def';
            if (in_array($aPos, $off, true)) $side = 'off';
        }

        $adj = [];
        if ($aPos !== '' && isset($adjacentMap[$aPos]) && is_array($adjacentMap[$aPos])) {
            $adj = array_values(array_unique(array_map(fn ($x) => strtoupper(trim((string) $x)), $adjacentMap[$aPos])));
        }

        if ($mode === 'any') {
            foreach ($candidates as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid === $aId) continue;
                $out[] = $c;
            }
            return $out;
        }

        foreach ($candidates as $c) {
            $cid = (int) ($c['id'] ?? 0);
            if ($cid === $aId) continue;

            $pos = (string) ($c['pos'] ?? '');
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

    private function rollFromMix(array $mix, string $default, array $keys): string
    {
        $r = mt_rand() / mt_getrandmax();
        $sum = 0.0;

        foreach ($keys as $k) {
            $sum += (float) ($mix[$k] ?? 0.0);
            if ($r <= $sum) return $k;
        }

        return $default;
    }

    private function rollBool(float $p): bool
    {
        if ($p <= 0) return false;
        if ($p >= 1) return true;
        return (mt_rand() / mt_getrandmax()) < $p;
    }

    private function pickWeighted(array $items): ?array
    {
        $total = 0.0;
        foreach ($items as $it) {
            $w = (float) ($it['w'] ?? 0.0);
            if ($w > 0) $total += $w;
        }

        if ($total <= 0) return null;

        $r = (mt_rand() / mt_getrandmax()) * $total;
        $acc = 0.0;

        foreach ($items as $it) {
            $w = (float) ($it['w'] ?? 0.0);
            if ($w <= 0) continue;

            $acc += $w;
            if ($r <= $acc) return $it;
        }

        return $items[count($items) - 1] ?? null;
    }

    private function expectedRatingMeta(?float $rating, ?string $posShort, string $attrKey, ?int $fplCost = null, ?int $maxCost = null, ?float $fplSel = null, ?float $maxSel = null): array
    {
        if ($rating !== null) {
            return ['value' => (float) $rating, 'source' => 'rating'];
        }

        $seedClass = 'App\\Support\\Seed';
        $seed = null;

        if ($posShort && class_exists($seedClass) && method_exists($seedClass, 'for')) {
            $v = $seedClass::for($posShort, $attrKey);
            if (is_numeric($v)) $seed = (float) $v;
        }

        if ($seed === null) $seed = 50.0;

        $val = $seed;
        $src = 'seed';

        $components = [
            'seed' => $seed,
        ];

        $mc = $maxCost !== null ? (int) $maxCost : 0;
        $fc = $fplCost !== null ? (int) $fplCost : 0;

        if ($mc > 0 && $fc > 0) {
            $delta = (float) (config('zcout_matchmaking.rating_proxy_cost_delta') ?? 30);
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

        $ms = $maxSel !== null ? (float) $maxSel : 0.0;
        $fs = $fplSel !== null ? (float) $fplSel : 0.0;

        if ($ms > 0 && $fs > 0) {
            $delta = (float) (config('zcout_matchmaking.rating_proxy_sel_delta') ?? 12);
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
            'value' => (float) $val,
            'source' => $src,
            'components' => $components,
        ];
    }

    public function skip()
    {
        $anon = request()->header('X-Zcout-Anon');
        $voterHash = $anon ?: (auth()->check() ? ('user:' . auth()->id()) : null);

        if (!$voterHash) {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $duelId = (int) request('duel_id');
        if ($duelId <= 0) {
            return response()->json(['error' => 'Missing duel_id'], 422);
        }

        DB::table('duel_skips')->updateOrInsert(
            [
                'duel_id' => $duelId,
                'voter_hash' => $voterHash,
            ],
            [
                'user_id' => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

        return response()->json(['ok' => true]);
    }

    private function posLine(string $posShort): string
    {
        $p = strtoupper(trim($posShort));

        if ($p === 'GK') return 'GK';

        $def = ['CB','LB','RB','LWB','RWB','WB'];
        if (in_array($p, $def, true)) return 'DEF';

        $fwd = ['ST','CF','LW','RW','LF','RF','ATT'];
        if (in_array($p, $fwd, true)) return 'FWD';

        return 'MID';
    }

    private function scopesToTry(string $scope): array
    {
        if ($scope === 'same_pos') return ['same_pos', 'same_line', 'any'];
        if ($scope === 'same_line') return ['same_line', 'any'];
        return ['any'];
    }

    private function scopesToTryForCategory(string $scope, string $category): array
    {
        if ($category === 'obvious') {
            return ['any'];
        }

        if (in_array($category, ['close', 'medium'], true)) {
            if ($scope === 'same_pos') return ['same_pos', 'same_line'];
            return ['same_line'];
        }

        return $this->scopesToTry($scope);
    }

    private function filterByScope(array $candidates, array $a, string $scope): array
    {
        $out = [];

        foreach ($candidates as $c) {
            if ((int) $c['id'] === (int) $a['id']) continue;

            if ($scope === 'same_pos') {
                if (($c['pos'] ?? null) !== ($a['pos'] ?? null)) continue;
            } elseif ($scope === 'same_line') {
                if (($c['line'] ?? null) !== ($a['line'] ?? null)) continue;
            }

            $out[] = $c;
        }

        return $out;
    }
}
