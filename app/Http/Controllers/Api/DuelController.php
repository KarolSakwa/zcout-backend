<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use App\Matchmaking\DuelMatchmakingInputResolver;
use App\Matchmaking\ProductionDuelPlanner;
use App\Matchmaking\CalibrationOpportunitySelector;

class DuelController extends Controller
{
    private DuelMatchmakingInputResolver $matchmakingInputResolver;
    private ProductionDuelPlanner $productionDuelPlanner;
    private CalibrationOpportunitySelector $calibrationOpportunitySelector;

    public function __construct(
        DuelMatchmakingInputResolver $matchmakingInputResolver,
        ProductionDuelPlanner $productionDuelPlanner,
        CalibrationOpportunitySelector $calibrationOpportunitySelector
    ) {
        $this->matchmakingInputResolver = $matchmakingInputResolver;
        $this->productionDuelPlanner = $productionDuelPlanner;
        $this->calibrationOpportunitySelector = $calibrationOpportunitySelector;
    }

    public function next()
    {
        $anon = request()->header('X-Zcout-Anon');
        $voterHash = $anon ?: (auth()->check() ? ('user:' . auth()->id()) : null);

        if (!$voterHash) {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $voteVoterHash = hash_hmac('sha256', $voterHash, (string)config('app.key'));

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
                        $playerIds = [(int)$lockedDuel->player_a_id, (int)$lockedDuel->player_b_id];

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

                        $pA = $players->get((int)$lockedDuel->player_a_id);
                        $pB = $players->get((int)$lockedDuel->player_b_id);

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
                                    'category' => null,
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
            $skipped[(int)$id] = true;
        }

        $votedIds = DB::table('votes')
            ->where('source', 'duel')
            ->where('voter_hash', $voteVoterHash)
            ->pluck('duel_id')
            ->all();

        $voted = [];
        foreach ($votedIds as $id) {
            $voted[(int)$id] = true;
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

            $intent = $mm['intent'];
            $selectedTier = $mm['tier'];
            $positionProfile = $mm['position_profile'] ?? 'adjacent';
            $gapProfile = $mm['gap_profile'] ?? 'close';

            $positionalAdjacent = $mm['positional_adjacent'];
            $positionalSides = $mm['positional_sides'];
            $gaps = $mm['rating_gap'];

            $needPow = $mm['need_pow'];

            $requested = $mm['requested'];
            $picked = $mm['picked'];

            $fallbacks = [];

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

            if (!$forceGK && $intent === 'production') {
                $rowsQ->where('pos.short_label', '!=', 'GK');
            }

            $rows = $rowsQ
                ->selectRaw('p.id, pos.short_label as pos_short, prs.player_rep, (COALESCE(par.confidence, 0) / 100.0) as attr_confidence, par.rating as attr_rating, COALESCE(prs.fpl_now_cost, 0) as fpl_cost, COALESCE(prs.fpl_selected_by_percent, 0) as fpl_sel')
                ->get();

            if ($rows->count() < 2) {
                return response()->json(['error' => 'Not enough players in pool'], 422);
            }

            $candidates = [];
            foreach ($rows as $r) {
                $posShort = $r->pos_short ? (string)$r->pos_short : null;
                if (!$posShort) continue;

                $rep = (float)$r->player_rep;
                $conf = (float)$r->attr_confidence;

                $need = 1.0 - $conf;
                if ($need < 0) $need = 0.0;
                if ($need > 1) $need = 1.0;

                $w = pow(max($need, 0.000001), $needPow);

                if ($w > 0) {
                    $candidates[] = [
                        'id' => (int)$r->id,
                        'pos' => $posShort,
                        'line' => $this->posLine($posShort),
                        'rep' => $rep,
                        'conf' => $conf,
                        'rating' => $r->attr_rating !== null ? (float)$r->attr_rating : null,
                        'cost' => (int)($r->fpl_cost ?? 0),
                        'sel' => (float)($r->fpl_sel ?? 0),
                        'w' => (float)$w,
                    ];
                }
            }

            if (count($candidates) < 2) {
                return response()->json(['error' => 'Not enough weighted candidates'], 422);
            }

            $maxCost = 0;
            $maxSel = 0.0;

            foreach ($candidates as $c) {
                $cc = (int)($c['cost'] ?? 0);
                if ($cc > $maxCost) $maxCost = $cc;

                $ss = (float)($c['sel'] ?? 0);
                if ($ss > $maxSel) $maxSel = $ss;
            }

            $triesUsed = 0;

            $pickedA = null;
            $pickedB = null;

            $selectedPositionalMode = null;
            $selectedGapProfile = $intent === 'production' ? $gapProfile : null;

            $metaA = null;
            $metaB = null;
            $gap = null;

            $baseCandidates = $candidates;

            if (!$forceGK && $intent === 'calibration') {
                $tmp = [];
                foreach ($candidates as $c) {
                    if (($c['pos'] ?? null) !== 'GK') $tmp[] = $c;
                }
                if (count($tmp) >= 2) $baseCandidates = $tmp;
            }

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

                if (($planned['picked_a'] ?? null) && ($planned['picked_b'] ?? null)) {
                    $pickedA = $planned['picked_a'];
                    $pickedB = $planned['picked_b'];
                    $selectedPositionalMode = $planned['selected_positional_mode'];
                    $gap = $planned['gap'];
                    $metaA = $planned['meta_a'];
                    $metaB = $planned['meta_b'];
                    $triesUsed = (int) ($planned['tries_used'] ?? 0);

                    foreach (($planned['fallbacks'] ?? []) as $fallback) {
                        $fallbacks[] = $fallback;
                    }
                }
            }

            if ($intent === 'calibration') {
                $planned = $this->calibrationOpportunitySelector->handle([
                    'candidates' => $baseCandidates,
                    'attribute_key' => $attribute->key,
                    'gaps' => $gaps,
                    'max_cost' => $maxCost,
                    'max_sel' => $maxSel,
                ]);

                if (($planned['picked_a'] ?? null) && ($planned['picked_b'] ?? null)) {
                    $pickedA = $planned['picked_a'];
                    $pickedB = $planned['picked_b'];
                    $selectedPositionalMode = $planned['selected_positional_mode'];
                    $gap = $planned['gap'];
                    $metaA = $planned['meta_a'];
                    $metaB = $planned['meta_b'];
                    $triesUsed = (int) ($planned['tries_used'] ?? 0);

                    foreach (($planned['fallbacks'] ?? []) as $fallback) {
                        $fallbacks[] = $fallback;
                    }
                }
            }

            if (!$pickedA || !$pickedB) {
                return response()->json(['error' => 'Failed to pick duel pair'], 422);
            }

            $playerIds = [(int)$pickedA['id'], (int)$pickedB['id']];

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

            $pA = $players[(int)$pickedA['id']];
            $pB = $players[(int)$pickedB['id']];

            $playerAId = min($pA->id, $pB->id);
            $playerBId = max($pA->id, $pB->id);

            $duel = Duel::firstOrCreate([
                'attribute_id' => $attribute->id,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
            ]);

            if (isset($skipped[(int)$duel->id])) {
                $fallbacks[] = 'skipped_reroll';
                continue;
            }

            if (isset($voted[(int)$duel->id])) {
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
                    'category' => $intent === 'calibration' ? $mm['category'] : null,
                    'positional_mode' => $intent === 'production' ? $selectedPositionalMode : null,
                    'intent' => $mm['intent'],
                    'tier' => $mm['tier'],
                    'gap_profile' => $intent === 'production' ? $selectedGapProfile : null,
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

    public function skip()
    {
        $anon = request()->header('X-Zcout-Anon');
        $voterHash = $anon ?: (auth()->check() ? ('user:' . auth()->id()) : null);

        if (!$voterHash) {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $duelId = (int)request('duel_id');
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

        $def = ['CB', 'LB', 'RB', 'LWB', 'RWB', 'WB'];
        if (in_array($p, $def, true)) return 'DEF';

        $fwd = ['ST', 'CF', 'LW', 'RW', 'LF', 'RF', 'ATT'];
        if (in_array($p, $fwd, true)) return 'FWD';

        return 'MID';
    }
}
