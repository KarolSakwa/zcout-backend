<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Position;
use App\Support\OverallConfidence;
use App\Support\OverallConfig;
use App\Support\RadarAxesBuilder;
use App\Support\Seed;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\PlayerOverall;

class RankingController extends Controller
{
    public function meta()
    {
        $mapAttributeOptions = function (array $items) {
            return collect($items)
                ->map(function (array $item) {
                    $key = (string) ($item['key'] ?? '');

                    return [
                        'value' => $key,
                        'label' => strtoupper((string) ($item['label'] ?? str_replace('_', ' ', $key))),
                    ];
                })
                ->sortBy(fn (array $item) => mb_strtolower($item['label']), SORT_NATURAL)
                ->prepend([
                    'value' => 'overall',
                    'label' => 'OVERALL',
                ])
                ->values();
        };

        $outfieldAttributes = $mapAttributeOptions(config('zcout_attributes.outfield', []));
        $gkAttributes = $mapAttributeOptions(config('zcout_attributes.gk', []));

        $positionOrder = ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'LM', 'RM', 'AM', 'LW', 'RW', 'ST'];

        $positionsFromDb = Position::query()
            ->select('short_label')
            ->whereIn('short_label', $positionOrder)
            ->get()
            ->map(fn ($p) => strtoupper((string) $p->short_label))
            ->all();

        $positions = collect($positionOrder)
            ->filter(fn (string $pos) => in_array($pos, $positionsFromDb, true))
            ->map(fn (string $pos) => [
                'value' => $pos,
                'label' => $pos,
            ])
            ->prepend([
                'value' => 'ALL',
                'label' => 'ALL POSITIONS',
            ])
            ->values();

        return response()->json([
            'positions' => $positions,
            'outfield_attributes' => $outfieldAttributes,
            'gk_attributes' => $gkAttributes,
        ]);
    }

    public function attribute(string $attributeKey, Request $request)
    {
        $limit = (int) $request->query('limit', 25);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $page = max(1, (int) $request->query('page', 1));

        $position = strtoupper((string) $request->query('position', ''));
        if ($position === 'ALL') {
            $position = '';
        }

        $search = trim((string) $request->query('search', ''));
        $sort = $this->normalizeSort((string) $request->query('sort', 'rank'));
        $dir = $this->normalizeDir((string) $request->query('dir', 'asc'));

        $positionId = null;
        if ($position !== '') {
            $positionId = Position::query()
                ->where('short_label', $position)
                ->value('id');
        }

        $playersQuery = Player::query()
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

        $players = $playersQuery->get();

        if ($attributeKey === 'overall') {
            return $this->overall($players, $position, $limit, $page, $sort, $dir, $search);
        }

        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $attributeKey)
            ->first();

        if (!$attribute) {
            return response()->json(['message' => 'Attribute not found.'], 404);
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
                'player' => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->effective_name,
                    'club' => [
                        'name' => (string) ($p->clubRel?->name ?? $p->club ?? ''),
                        'slug' => $p->clubRel?->slug,
                    ],
                ],
                'pos' => $pos,
                'rating' => (float) round($rating, 3),
                'confidence' => (float) round($confidence, 3),
                'last_vote_at' => $lastVoteAt,
                'trend_7d' => isset($trendByPlayer[$p->id]) ? round((float) $trendByPlayer[$p->id], 3) : null,
            ];
        }

        $ranked = $this->rankAndSortItems($items, $sort, $dir, $limit, $page, $search);

        return response()->json([
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
        ]);
    }

    private function overall(Collection $players, string $position, int $limit, int $page, string $sort, string $dir, string $search = '')
    {
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

            $overall = $persistedOverall?->overall;
            $overallConfidence = $persistedOverall?->confidence ?? 0;

            $items[] = [
                'player' => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->effective_name,
                    'club' => [
                        'name' => (string) ($p->clubRel?->name ?? $p->club ?? ''),
                        'slug' => $p->clubRel?->slug,
                    ],
                ],
                'pos' => $pos,
                'rating' => (float) round((float) ($overall ?? 0), 3),
                'confidence' => $overallConfidence,
                'last_vote_at' => null,
                'trend_7d' => $this->computeOverallTrendDelta($pos, $attributeDeltaByPlayer[$p->id] ?? []),
            ];
        }

        $ranked = $this->rankAndSortItems($items, $sort, $dir, $limit, $page, $search);

        return response()->json([
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
        ]);
    }

    private function rankAndSortItems(array $items, string $sort, string $dir, int $limit, int $page, string $search = ''): array
    {
        usort($items, function ($a, $b) {
            $c = $b['rating'] <=> $a['rating'];
            if ($c !== 0) {
                return $c;
            }

            $c = $b['confidence'] <=> $a['confidence'];
            if ($c !== 0) {
                return $c;
            }

            return $a['player']['id'] <=> $b['player']['id'];
        });

        foreach ($items as $i => $it) {
            $items[$i]['rank'] = $i + 1;
        }

        if ($search !== '') {
            $needle = $this->normalizeSearchText($search);

            $items = array_values(array_filter($items, function (array $item) use ($needle) {
                $name = $this->normalizeSearchText((string) ($item['player']['name'] ?? ''));

                return str_contains($name, $needle);
            }));
        }

        if ($sort !== 'rank') {
            usort($items, function ($a, $b) use ($sort, $dir) {
                $result = match ($sort) {
                    'player' => $this->compareText($a['player']['name'], $b['player']['name']),
                    'club' => $this->compareText($a['player']['club']['name'] ?? '', $b['player']['club']['name'] ?? ''),
                    'pos' => $this->compareText($a['pos'], $b['pos']),
                    'rating' => $a['rating'] <=> $b['rating'],
                    'trend' => $this->compareNullableNumber($a['trend_7d'], $b['trend_7d'], $dir),
                    default => $a['rank'] <=> $b['rank'],
                };

                if ($result === 0) {
                    $result = $a['rank'] <=> $b['rank'];
                }

                if ($sort === 'trend') {
                    return $result;
                }

                return $dir === 'desc' ? -$result : $result;
            });
        } elseif ($dir === 'desc') {
            $items = array_reverse($items);
        }

        $totalItems = count($items);
        $totalPages = max(1, (int) ceil($totalItems / $limit));
        $safePage = min(max(1, $page), $totalPages);
        $offset = ($safePage - 1) * $limit;
        $pagedItems = array_slice(array_values($items), $offset, $limit);

        return [
            'items' => $pagedItems,
            'total' => $totalItems,
            'total_pages' => $totalPages,
            'page' => $safePage,
        ];
    }

    private function compareText(?string $a, ?string $b): int
    {
        $a = trim((string) $a);
        $b = trim((string) $b);

        if (class_exists(\Collator::class)) {
            $collator = new \Collator('pl_PL');
            $result = $collator->compare($a, $b);

            if ($result !== false) {
                return $result;
            }
        }

        return strcmp(
            mb_strtolower($a),
            mb_strtolower($b)
        );
    }

    private function compareNullableNumber($a, $b, string $dir = 'asc'): int
    {
        $aNull = $a === null;
        $bNull = $b === null;

        if ($aNull && $bNull) {
            return 0;
        }

        if ($aNull) {
            return 1;
        }

        if ($bNull) {
            return -1;
        }

        $result = (float) $a <=> (float) $b;

        return $dir === 'desc' ? -$result : $result;
    }

    private function normalizeSort(string $sort): string
    {
        $sort = trim(mb_strtolower($sort));

        return in_array($sort, ['rank', 'player', 'club', 'pos', 'rating', 'trend'], true)
            ? $sort
            : 'rank';
    }

    private function normalizeDir(string $dir): string
    {
        $dir = trim(mb_strtolower($dir));

        return $dir === 'desc' ? 'desc' : 'asc';
    }

    private function buildRadarAxesPayload(string $posCode, array $payloadAttrs): array
    {
        $axisConfigKey = $posCode === 'GK'
            ? 'zcout_attributes.gk_axes'
            : 'zcout_attributes.outfield_axes';

        $ratingsByKey = collect($payloadAttrs)
            ->mapWithKeys(fn (array $attr) => [
                (string) ($attr['key'] ?? '') => (float) ($attr['rating'] ?? 0),
            ]);

        return collect(config($axisConfigKey, []))
            ->map(function (array $attributeKeys, string $key) use ($ratingsByKey) {
                $values = collect($attributeKeys)
                    ->map(fn (string $attributeKey) => $ratingsByKey->get($attributeKey))
                    ->filter(fn ($value) => is_numeric($value))
                    ->values();

                $value = $values->isNotEmpty()
                    ? round((float) $values->avg(), 1)
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

    private function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
