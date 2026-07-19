<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\PlayerOverall;
use App\Models\Position;
use App\Services\Ranking\RankingResultBuilder;
use App\Support\OverallConfig;
use App\Support\Seed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BuildRankingAttributeAction
{
    public function __construct(
        private RankingResultBuilder $rankingResultBuilder,
    ) {}

    public function execute(
        string $attributeKey,
        string $position,
        int $limit,
        int $page,
        string $sort,
        string $dir,
        string $search = '',
    ): array {
        $players = $this->fetchPlayers($position);

        if ($attributeKey === 'overall') {
            return [
                'status' => 200,
                'body' => $this->buildOverallPayload($players, $position, $limit, $page, $sort, $dir, $search),
            ];
        }

        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $attributeKey)
            ->first();

        if (!$attribute) {
            return [
                'status' => 404,
                'body' => ['message' => 'Attribute not found.'],
            ];
        }

        $playerIds = $players->pluck('id')->all();

        $ratingRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy('player_id');

        $trendRows = DB::table('votes')
            ->where('attribute_id', $attribute->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('pre_rating_a')
            ->whereNotNull('post_rating_a')
            ->where(function ($q) use ($playerIds) {
                $q->whereIn('player_a_id', $playerIds)
                    ->orWhereIn('player_b_id', $playerIds);
            })
            ->get([
                'player_a_id',
                'player_b_id',
                'pre_rating_a',
                'post_rating_a',
                'pre_rating_b',
                'post_rating_b',
            ]);

        $trendByPlayer = [];

        foreach ($trendRows as $vote) {
            if (in_array((int) $vote->player_a_id, $playerIds, true)) {
                $deltaA = (float) $vote->post_rating_a - (float) $vote->pre_rating_a;
                $trendByPlayer[(int) $vote->player_a_id] = ($trendByPlayer[(int) $vote->player_a_id] ?? 0.0) + $deltaA;
            }

            if (
                $vote->player_b_id !== null &&
                in_array((int) $vote->player_b_id, $playerIds, true) &&
                $vote->pre_rating_b !== null &&
                $vote->post_rating_b !== null
            ) {
                $deltaB = (float) $vote->post_rating_b - (float) $vote->pre_rating_b;
                $trendByPlayer[(int) $vote->player_b_id] = ($trendByPlayer[(int) $vote->player_b_id] ?? 0.0) + $deltaB;
            }
        }

        $items = [];
        foreach ($players as $p) {
            $pos = strtoupper((string) ($p->effective_position_short ?? ''));
            $row = $ratingRows[$p->id] ?? null;

            $rating = (float) ($row?->rating ?? Seed::for($pos, $attribute->key));
            $confidence = (float) ($row?->confidence ?? 0);
            $lastVoteAt = $row?->last_vote_at;

            $items[] = [
                'player' => $this->buildPlayerPayload($p),
                'pos' => $pos,
                'rating' => (float) round($rating, 3),
                'confidence' => (float) round($confidence, 3),
                'last_vote_at' => $lastVoteAt,
                'trend_7d' => isset($trendByPlayer[$p->id]) ? round((float) $trendByPlayer[$p->id], 3) : null,
            ];
        }

        $ranked = $this->rankingResultBuilder->rankAndSortItems($items, $sort, $dir, $limit, $page, $search);

        return [
            'status' => 200,
            'body' => [
                'attribute' => [
                    'id' => (int) $attribute->id,
                    'key' => (string) $attribute->key,
                ],
                'filters' => [
                    'position' => $position === '' ? 'ALL' : $position,
                    'limit' => $limit,
                    'page' => $ranked['page'],
                    'sort' => $sort,
                    'dir' => $dir,
                ],
                'total' => $ranked['total'],
                'total_pages' => $ranked['total_pages'],
                'items' => $ranked['items'],
            ],
        ];
    }

    private function fetchPlayers(string $position): Collection
    {
        $positionId = null;
        if ($position !== '') {
            $positionId = Position::query()
                ->where('short_label', $position)
                ->value('id');
        }

        $playersQuery = Player::query()
            ->inCurrentPremierLeague()
            ->select(
                'id',
                'name',
                'fd_name',
                'manual_display_name',
                'club',
                'club_id',
                'position_id',
                'fd_position_id',
                'manual_position_id'
            )
            ->with([
                'positionRef:id,short_label,key,label',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
                'clubRel:id,name,slug',
            ]);

        if ($position !== '') {
            if ($positionId === null) {
                $playersQuery->whereRaw('1 = 0');
            } else {
                $playersQuery->whereRaw(
                    'COALESCE(players.manual_position_id, players.fd_position_id, players.position_id) = ?',
                    [$positionId]
                );
            }
        }

        return $playersQuery->get();
    }

    private function buildOverallPayload(
        Collection $players,
        string $position,
        int $limit,
        int $page,
        string $sort,
        string $dir,
        string $search = '',
    ): array {
        $attributeKeys = collect($position === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', [])
        )->pluck('key');

        $attributes = Attribute::query()
            ->select('id', 'key', 'label', 'group')
            ->whereIn('key', $attributeKeys)
            ->get();

        $rowsByPlayer = PlayerAttributeRating::query()
            ->whereIn('player_id', $players->pluck('id'))
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get()
            ->groupBy('player_id');

        $playerIds = $players->pluck('id')->all();
        $attributeKeysById = $attributes->pluck('key', 'id')->all();

        $trendRows = DB::table('votes')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('pre_rating_a')
            ->whereNotNull('post_rating_a')
            ->where(function ($q) use ($playerIds) {
                $q->whereIn('player_a_id', $playerIds)
                    ->orWhereIn('player_b_id', $playerIds);
            })
            ->get([
                'attribute_id',
                'player_a_id',
                'player_b_id',
                'pre_rating_a',
                'post_rating_a',
                'pre_rating_b',
                'post_rating_b',
            ]);

        $attributeDeltaByPlayer = [];

        foreach ($trendRows as $vote) {
            $attributeKey = $attributeKeysById[$vote->attribute_id] ?? null;

            if (! $attributeKey) {
                continue;
            }

            if (in_array((int) $vote->player_a_id, $playerIds, true)) {
                $deltaA = (float) $vote->post_rating_a - (float) $vote->pre_rating_a;

                $attributeDeltaByPlayer[(int) $vote->player_a_id][$attributeKey] =
                    ($attributeDeltaByPlayer[(int) $vote->player_a_id][$attributeKey] ?? 0.0) + $deltaA;
            }

            if (
                $vote->player_b_id !== null &&
                in_array((int) $vote->player_b_id, $playerIds, true) &&
                $vote->pre_rating_b !== null &&
                $vote->post_rating_b !== null
            ) {
                $deltaB = (float) $vote->post_rating_b - (float) $vote->pre_rating_b;

                $attributeDeltaByPlayer[(int) $vote->player_b_id][$attributeKey] =
                    ($attributeDeltaByPlayer[(int) $vote->player_b_id][$attributeKey] ?? 0.0) + $deltaB;
            }
        }

        $items = [];

        foreach ($players as $p) {
            $pos = strtoupper((string) ($p->effective_position_short ?? ''));
            $playerRows = $rowsByPlayer->get($p->id, collect())->keyBy('attribute_id');

            $payloadAttrs = [];

            foreach ($attributes as $attr) {
                $row = $playerRows->get($attr->id);

                $rating = $row ? (float) $row->rating : (float) Seed::for($pos, $attr->key);

                $payloadAttrs[] = [
                    'key' => (string) $attr->key,
                    'rating' => (float) $rating,
                    'confidence' => (float) ($row?->confidence ?? 0),
                ];
            }

            $persistedOverall = PlayerOverall::query()
                ->where('player_id', $p->id)
                ->where('position', $pos)
                ->first();

            $overall = $persistedOverall
                ? (float) $persistedOverall->overall
                : null;

            $overallConfidence = $persistedOverall
                ? (float) $persistedOverall->confidence
                : 0;

            $items[] = [
                'player' => $this->buildPlayerPayload($p),
                'pos' => $pos,
                'rating' => (float) round((float) ($overall ?? 0), 3),
                'confidence' => $overallConfidence,
                'last_vote_at' => null,
                'trend_7d' => $this->computeOverallTrendDelta($pos, $attributeDeltaByPlayer[$p->id] ?? []),
            ];
        }

        $ranked = $this->rankingResultBuilder->rankAndSortItems($items, $sort, $dir, $limit, $page, $search);

        return [
            'attribute' => [
                'id' => 0,
                'key' => 'overall',
            ],
            'filters' => [
                'position' => $position === '' ? 'ALL' : $position,
                'limit' => $limit,
                'page' => $ranked['page'],
                'sort' => $sort,
                'dir' => $dir,
            ],
            'total' => $ranked['total'],
            'total_pages' => $ranked['total_pages'],
            'items' => $ranked['items'],
        ];
    }

    private function buildPlayerPayload(Player $player): array
    {
        return [
            'id' => (int) $player->id,
            'name' => (string) $player->effective_name,
            'club' => [
                'name' => (string) ($player->clubRel?->name ?? $player->club ?? ''),
                'slug' => $player->clubRel?->slug,
            ],
        ];
    }

    private function computeOverallTrendDelta(string $posCode, array $attributeDeltasByKey): ?float
    {
        if ($attributeDeltasByKey === []) {
            return null;
        }

        $resolvedWeights = OverallConfig::resolvedAxisWeightsForPosition($posCode);

        if ($resolvedWeights === []) {
            return null;
        }

        $attributeConfigKey = $posCode === 'GK'
            ? 'zcout_attributes.gk'
            : 'zcout_attributes.outfield';

        $axisConfigKey = $posCode === 'GK'
            ? 'zcout_attributes.gk_axes'
            : 'zcout_attributes.outfield_axes';

        $existingAttributeKeys = collect(config($attributeConfigKey, []))
            ->pluck('key')
            ->flip();

        $axisConfig = config($axisConfigKey, []);

        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($resolvedWeights as $axisKey => $weight) {
            $axisAttributeKeys = collect($axisConfig[$axisKey] ?? [])
                ->filter(fn (string $key) => $existingAttributeKeys->has($key))
                ->values();

            if ($axisAttributeKeys->isEmpty()) {
                continue;
            }

            $axisDeltaSum = $axisAttributeKeys
                ->sum(fn (string $key) => (float) ($attributeDeltasByKey[$key] ?? 0.0));

            $axisDelta = $axisDeltaSum / $axisAttributeKeys->count();

            $weightedSum += $axisDelta * (float) $weight;
            $weightSum += (float) $weight;
        }

        if ($weightSum <= 0) {
            return null;
        }

        return round($weightedSum / $weightSum, 3);
    }
}
