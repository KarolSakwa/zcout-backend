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
use App\Matchmaking\MatchmakingCandidatePoolBuilder;
use App\Matchmaking\MatchmakingCandidateRowFetcher;
use App\Actions\GetNextDuelAction;

class DuelController extends Controller
{
    private DuelMatchmakingInputResolver $matchmakingInputResolver;
    private ProductionDuelPlanner $productionDuelPlanner;
    private CalibrationOpportunitySelector $calibrationOpportunitySelector;
    private MatchmakingCandidatePoolBuilder $candidatePoolBuilder;
    private MatchmakingCandidateRowFetcher $candidateRowFetcher;
    private GetNextDuelAction $getNextDuelAction;

    public function __construct(
        DuelMatchmakingInputResolver $matchmakingInputResolver,
        ProductionDuelPlanner $productionDuelPlanner,
        CalibrationOpportunitySelector $calibrationOpportunitySelector,
        MatchmakingCandidatePoolBuilder $candidatePoolBuilder,
        MatchmakingCandidateRowFetcher $candidateRowFetcher,
        GetNextDuelAction $getNextDuelAction
    ) {
        $this->matchmakingInputResolver = $matchmakingInputResolver;
        $this->productionDuelPlanner = $productionDuelPlanner;
        $this->calibrationOpportunitySelector = $calibrationOpportunitySelector;
        $this->candidatePoolBuilder = $candidatePoolBuilder;
        $this->candidateRowFetcher = $candidateRowFetcher;
        $this->getNextDuelAction = $getNextDuelAction;
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

            $cfg = config('zcout_matchmaking', []);
            $planned = $this->getNextDuelAction->handle([
                'attribute' => $attribute,
                'cfg' => $cfg,
            ]);

            $attribute = $planned['attribute'] ?? $attribute;
            $intent = $planned['intent'] ?? null;
            $selectedTier = $planned['tier'] ?? null;
            $gapProfile = $planned['gap_profile'] ?? null;
            $selectedPositionalMode = $planned['positional_mode'] ?? null;
            $gap = $planned['gap'] ?? null;
            $metaA = $planned['meta_a'] ?? null;
            $metaB = $planned['meta_b'] ?? null;
            $triesUsed = (int) ($planned['tries_used'] ?? 0);
            $fallbacks = $planned['fallbacks'] ?? [];
            $forceGK = (bool) ($planned['force_gk'] ?? false);
            $requested = $planned['requested'] ?? [];
            $picked = $planned['picked'] ?? [];
            $category = $planned['category'] ?? null;
            $debug = (string) request('debug') === '1';

            $pickedA = $planned['picked_a'] ?? null;
            $pickedB = $planned['picked_b'] ?? null;

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
                    'category' => $category,
                    'positional_mode' => $intent === 'production' ? $selectedPositionalMode : null,
                    'intent' => $intent,
                    'tier' => $selectedTier,
                    'gap_profile' => $intent === 'production' ? $gapProfile : null,
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
}
