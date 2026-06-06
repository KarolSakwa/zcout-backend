<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Support\RadarAxesBuilder;
use App\Support\Seed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Support\OverallConfig;
use App\Models\Vote;
use App\Support\OverallConfidence;
use App\Models\PlayerOverall;

class PlayerController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'club' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $player = Player::create($data);

        return response()->json(['id' => $player->id], 201);
    }

    public function featured()
    {
        $player = Player::query()
            ->join('player_reputation_stats as prs', 'prs.player_id', '=', 'players.id')
            ->where('prs.tier', 'A')
            ->inRandomOrder()
            ->select('players.*')
            ->firstOrFail();

        $overall = PlayerOverall::query()
            ->where('player_id', $player->id)
            ->first();

        $rank = null;

        if ($overall) {
            $rank = PlayerOverall::query()
                    ->where('overall', '>', $overall->overall)
                    ->count() + 1;
        }

        return $this->show($player, $rank);
    }

    public function show(Player $player, ?int $rank = null)
    {
        $player = Player::query()
            ->select(
                'id',
                'name',
                'slug',
                'club_id',
                'country_id',
                'position_id',
                'number',
                'date_of_birth',
                'fd_name',
                'fd_number',
                'manual_display_name',
                'manual_number',
                'fd_position_id',
                'manual_position_id',
            )
            ->with([
                'clubRel:id,name,slug,color_primary,color_secondary,color_tertiary',
                'countryRef:id,name,iso2',
                'positionRef:id,short_label',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
            ])
            ->whereKey($player->id)
            ->firstOrFail();

        $posCode = strtoupper((string) ($player->effective_position_short ?? ''));

        $attributeKeys = collect($posCode === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', [])
        )->pluck('key');

        $attributeConfig = $posCode === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', []);

        $attributeMetaByKey = collect($attributeConfig)
            ->keyBy('key');

        $attributeKeys = collect($attributeConfig)->pluck('key')->values();
        $attributeOrder = $attributeKeys
            ->values()
            ->flip()
            ->map(fn ($index) => (int) $index)
            ->all();

        $attributeKeys = collect($posCode === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', [])
        )->pluck('key');

        $attributeConfig = $posCode === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', []);

        $attributeKeys = collect($attributeConfig)->pluck('key')->values();
        $attributeOrder = $attributeKeys
            ->values()
            ->flip()
            ->map(fn ($index) => (int) $index)
            ->all();

        $attributes = Attribute::query()
            ->select('id', 'key', 'label', 'group')
            ->whereIn('key', $attributeKeys)
            ->get()
            ->sortBy(fn (Attribute $attribute) => $attributeOrder[$attribute->key] ?? PHP_INT_MAX)
            ->values();

        $rows = PlayerAttributeRating::query()
            ->where('player_id', $player->id)
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get()
            ->keyBy('attribute_id');

        $userId = auth('sanctum')->id();

        $userDirectVotesByAttributeId = collect();

        if ($userId) {
            $userDirectVotesByAttributeId = Vote::query()
                ->select('attribute_id', 'value', 'created_at')
                ->where('source', 'direct')
                ->where('user_id', $userId)
                ->where('player_a_id', $player->id)
                ->whereIn('attribute_id', $attributes->pluck('id'))
                ->get()
                ->keyBy('attribute_id');
        }

        $attributeKeysById = $attributes
            ->mapWithKeys(fn (Attribute $attribute) => [(int) $attribute->id => (string) $attribute->key])
            ->all();

        $trendRows = Vote::query()
            ->select([
                'source',
                'attribute_id',
                'player_a_id',
                'player_b_id',
                'pre_rating_a',
                'post_rating_a',
                'pre_rating_b',
                'post_rating_b',
            ])
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('pre_rating_a')
            ->whereNotNull('post_rating_a')
            ->where(function ($q) use ($player) {
                $q->where('player_a_id', $player->id)
                    ->orWhere('player_b_id', $player->id);
            })
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get();

        $attributeDeltasByKey = [];

        foreach ($trendRows as $vote) {
            $attributeKey = $attributeKeysById[(int) $vote->attribute_id] ?? null;

            if (! $attributeKey) {
                continue;
            }

            $delta = null;

            if ((int) $vote->player_a_id === (int) $player->id) {
                $delta = (float) $vote->post_rating_a - (float) $vote->pre_rating_a;
            } elseif (
                (int) $vote->player_b_id === (int) $player->id &&
                $vote->pre_rating_b !== null &&
                $vote->post_rating_b !== null
            ) {
                $delta = (float) $vote->post_rating_b - (float) $vote->pre_rating_b;
            }

            if ($delta === null) {
                continue;
            }

            $attributeDeltasByKey[$attributeKey] = ($attributeDeltasByKey[$attributeKey] ?? 0.0) + $delta;
        }

        $payloadAttrs = [];

        foreach ($attributes as $attr) {
            $row = $rows->get($attr->id);
            $userVote = $userDirectVotesByAttributeId->get($attr->id);

            $rating = $row ? (float) $row->rating : (float) Seed::for($posCode, $attr->key);
            $confidence = $row ? (float) ($row->confidence ?? 0) : 0.0;
            $ratingWeightSum = $row ? (float) ($row->rating_weight_sum ?? 0) : 0.0;
            $confidenceWeightSum = $row ? (float) ($row->confidence_weight_sum ?? 0) : 0.0;
            $votesCount = $row ? (int) ($row->votes_count ?? 0) : 0;
            $lastVoteAt = $row ? ($row->last_vote_at ? (string) $row->last_vote_at : null) : null;

            $payloadAttrs[] = [
                'id' => (int) $attr->id,
                'key' => (string) $attr->key,
                'label' => (string) ($attributeMetaByKey->get($attr->key)['label'] ?? $attr->label),
                'group' => (string) ($attributeMetaByKey->get($attr->key)['group'] ?? $attr->group),
                'rating' => (float) $rating,
                'confidence' => (float) min(100.0, round($confidence, 2)),
                'rating_weight_sum' => (float) $ratingWeightSum,
                'confidence_weight_sum' => (float) $confidenceWeightSum,
                'votes_count' => (int) $votesCount,
                'last_vote_at' => $lastVoteAt,
                'your_rating' => $userVote ? (int) $userVote->value : null,
                'your_rating_updated_at' => $userVote?->created_at ? (string) $userVote->created_at : null,
                'trend_7d' => array_key_exists((string) $attr->key, $attributeDeltasByKey)
                    ? round((float) $attributeDeltasByKey[(string) $attr->key], 3)
                    : null,
            ];
        }

        $radarAxes = RadarAxesBuilder::build($posCode, $payloadAttrs);
        $persistedOverall = PlayerOverall::query()
            ->where('player_id', $player->id)
            ->where('position', $posCode)
            ->first();

        $overall = $persistedOverall
            ? (float) $persistedOverall->overall
            : null;

        $overallConfidence = $persistedOverall
            ? (float) $persistedOverall->confidence
            : 0;
        $overallTrend7d = $this->computeOverallTrendDeltaFromPayload($posCode, $payloadAttrs);

        $previousPlayer = PlayerOverall::query()
            ->where(function ($query) use ($overall, $player) {
                $query
                    ->where('overall', '>', $overall)
                    ->orWhere(function ($query) use ($overall, $player) {
                        $query
                            ->where('overall', $overall)
                            ->where('player_id', '>', $player->id);
                    });
            })
            ->orderBy('overall')
            ->orderBy('player_id')
            ->with('player:id,slug')
            ->first();

        $nextPlayer = PlayerOverall::query()
            ->where(function ($query) use ($overall, $player) {
                $query
                    ->where('overall', '<', $overall)
                    ->orWhere(function ($query) use ($overall, $player) {
                        $query
                            ->where('overall', $overall)
                            ->where('player_id', '<', $player->id);
                    });
            })
            ->orderByDesc('overall')
            ->orderByDesc('player_id')
            ->with('player:id,slug')
            ->first();

        return response()->json([
            'id' => (int) $player->id,
            'name' => (string) $player->effective_name,
            'slug' => $player->slug,
            'number' => $player->effective_number,
            'date_of_birth' => $player->date_of_birth,
            'position' => $player->effective_position_short,
            'club' => $player->clubRel ? [
                'id' => (int) $player->clubRel->id,
                'name' => (string) $player->clubRel->name,
                'slug' => (string) $player->clubRel->slug,
                'color_primary' => $player->clubRel->color_primary,
                'color_secondary' => $player->clubRel->color_secondary,
                'color_tertiary' => $player->clubRel->color_tertiary,
            ] : null,
            'country' => $player->countryRef ? [
                'id' => (int) $player->countryRef->id,
                'name' => (string) $player->countryRef->name,
                'iso2' => $player->countryRef->iso2,
            ] : null,
            'overall_confidence' => $overallConfidence,
            'overall_trend_7d' => $overallTrend7d,
            'radar_axes' => $radarAxes,
            'attributes' => $payloadAttrs,
            'overall' => $overall,
            'previous_player_id' => $previousPlayer?->player_id,
            'next_player_id' => $nextPlayer?->player_id,
            'rank' => $rank,
        ]);
    }

    private function buildRadarAxesPayload(string $posCode, array $payloadAttrs): array
    {
        $axes = $posCode === 'GK'
            ? config('zcout_attributes.gk_axes')
            : config('zcout_attributes.outfield_axes');

        $attributeAxisCount = [];

        foreach ($axes as $attributes) {
            foreach ($attributes as $attr) {
                if (!isset($attributeAxisCount[$attr])) {
                    $attributeAxisCount[$attr] = 0;
                }
                $attributeAxisCount[$attr]++;
            }
        }

        $ratingsByKey = collect($payloadAttrs)
            ->mapWithKeys(fn (array $attr) => [
                (string) $attr['key'] => (float) $attr['rating'],
            ]);

        return collect($axes)
            ->map(function (array $attributeKeys, string $key) use ($ratingsByKey, $attributeAxisCount) {

                $weightedSum = 0.0;
                $weightSum = 0.0;

                foreach ($attributeKeys as $attributeKey) {
                    $raw = $ratingsByKey->get($attributeKey);

                    if (!is_numeric($raw)) {
                        continue;
                    }

                    $count = $attributeAxisCount[$attributeKey] ?? 1;

                    $weight = 1 / $count;

                    $weightedSum += ((float) $raw) * $weight;
                    $weightSum += $weight;
                }

                $value = $weightSum > 0
                    ? round($weightedSum / $weightSum, 1)
                    : 0.0;

                return [
                    'key' => $key,
                    'label' => Str::of($key)->replace('_', ' ')->upper()->toString(),
                    'attribute_keys' => $attributeKeys,
                    'value' => (float) $value,
                ];
            })
            ->values()
            ->all();
    }

    private function computeOverallTrendDeltaFromPayload(string $posCode, array $payloadAttrs): ?float
    {
        $hasAnyTrend = collect($payloadAttrs)->contains(
            fn (array $attr) => is_numeric($attr['trend_7d'] ?? null) && abs((float) $attr['trend_7d']) > 0.0005
        );

        if (! $hasAnyTrend) {
            return null;
        }

        $currentAxes = $this->buildRadarAxesPayload($posCode, $payloadAttrs);

        $previousPayloadAttrs = collect($payloadAttrs)
            ->map(function (array $attr) {
                $delta = is_numeric($attr['trend_7d'] ?? null) ? (float) $attr['trend_7d'] : 0.0;

                return [
                    ...$attr,
                    'rating' => max(0.0, min(99.0, (float) $attr['rating'] - $delta)),
                ];
            })
            ->values()
            ->all();

        $previousAxes = $this->buildRadarAxesPayload($posCode, $previousPayloadAttrs);

        $currentOverall = OverallConfig::overallFromRadarAxes($posCode, $currentAxes);
        $previousOverall = OverallConfig::overallFromRadarAxes($posCode, $previousAxes);

        if ($currentOverall === null || $previousOverall === null) {
            return null;
        }

        $delta = (float) $currentOverall - (float) $previousOverall;

        return abs($delta) > 0.0005 ? round($delta, 3) : null;
    }
}
