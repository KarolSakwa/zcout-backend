<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Support\OverallConfig;
use App\Support\Seed;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        if ($q === '') {
            return response()->json([
                'query' => '',
                'players' => [],
                'clubs' => [],
            ]);
        }

        $needle = mb_strtolower($q);
        $prefix = $needle . '%';
        $contains = '%' . $needle . '%';

        $playerRows = Player::query()
            ->select(
                'players.id',
                'players.name',
                'players.fd_name',
                'players.manual_display_name',
                'players.slug',
                'players.position_id',
                'clubs.name as club_name',
                'positions.short_label as position'
            )
            ->selectRaw(
                "CASE
            WHEN LOWER(COALESCE(players.manual_display_name, players.fd_name, players.name)) = ? THEN 0
            WHEN LOWER(COALESCE(players.manual_display_name, players.fd_name, players.name)) LIKE ? THEN 1
            WHEN LOWER(players.slug) LIKE ? THEN 2
            ELSE 3
        END as match_rank",
                [$needle, $prefix, $prefix]
            )
            ->leftJoin('clubs', 'clubs.id', '=', 'players.club_id')
            ->leftJoin('positions', 'positions.id', '=', 'players.position_id')
            ->where(function ($query) use ($contains) {
                $query
                    ->whereRaw('LOWER(COALESCE(players.manual_display_name, players.fd_name, players.name)) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(players.slug) LIKE ?', [$contains]);
            })
            ->orderBy('match_rank')
            ->orderByRaw('COALESCE(players.manual_display_name, players.fd_name, players.name)')
            ->limit(8)
            ->get();

        $attributes = Attribute::query()
            ->select('id', 'key')
            ->orderBy('key')
            ->get();

        $attributeIds = $attributes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attributeKeysById = $attributes->mapWithKeys(fn ($attr) => [(int) $attr->id => (string) $attr->key]);

        $playerIds = $playerRows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ratingsByPlayer = [];
        if (!empty($playerIds) && !empty($attributeIds)) {
            $ratingRows = PlayerAttributeRating::query()
                ->select('player_id', 'attribute_id', 'rating')
                ->whereIn('player_id', $playerIds)
                ->whereIn('attribute_id', $attributeIds)
                ->get();

            foreach ($ratingRows as $row) {
                $pid = (int) $row->player_id;
                $aid = (int) $row->attribute_id;

                if (!isset($ratingsByPlayer[$pid])) {
                    $ratingsByPlayer[$pid] = [];
                }

                $ratingsByPlayer[$pid][$aid] = (float) $row->rating;
            }
        }

        $players = $playerRows
            ->map(function ($player) use ($attributes, $attributeKeysById, $ratingsByPlayer) {
                $pid = (int) $player->id;
                $posCode = strtoupper((string) ($player->position ?? ''));

                $ratingsByKey = [];

                foreach ($attributes as $attr) {
                    $aid = (int) $attr->id;
                    $key = $attributeKeysById[$aid] ?? null;

                    if ($key === null) {
                        continue;
                    }

                    $ratingsByKey[$key] = $ratingsByPlayer[$pid][$aid] ?? (float) Seed::for($posCode, $key);
                }

                $axisConfigKey = $posCode === 'GK'
                    ? 'zcout_attributes.gk_axes'
                    : 'zcout_attributes.outfield_axes';

                $radarAxes = collect(config($axisConfigKey, []))
                    ->map(function (array $attributeKeys, string $axisKey) use ($ratingsByKey) {
                        $values = collect($attributeKeys)
                            ->map(fn (string $attributeKey) => $ratingsByKey[$attributeKey] ?? null)
                            ->filter(fn ($value) => is_numeric($value))
                            ->values();

                        return [
                            'key' => $axisKey,
                            'value' => $values->isNotEmpty()
                                ? round((float) $values->avg(), 1)
                                : 0.0,
                        ];
                    })
                    ->values()
                    ->all();

                $overall = OverallConfig::overallFromRadarAxes($posCode, $radarAxes);

                return [
                    'id' => $pid,
                    'name' => (string) $player->effective_name,
                    'slug' => $player->slug ? (string) $player->slug : null,
                    'position' => $player->position ? (string) $player->position : null,
                    'club' => $player->club_name ? (string) $player->club_name : null,
                    'overall' => $overall,
                    'match_rank' => (int) $player->match_rank,
                ];
            })
            ->sort(function ($a, $b) {
                if ($a['match_rank'] !== $b['match_rank']) {
                    return $a['match_rank'] <=> $b['match_rank'];
                }

                $aOverall = $a['overall'] ?? -1;
                $bOverall = $b['overall'] ?? -1;

                if ($aOverall !== $bOverall) {
                    return $bOverall <=> $aOverall;
                }

                return strcmp($a['name'], $b['name']);
            })
            ->values()
            ->map(function ($player) {
                unset($player['match_rank']);
                return $player;
            });

        $clubs = Club::query()
            ->select('id', 'name', 'slug')
            ->where(function ($query) use ($contains) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$contains]);
            })
            ->orderByRaw(
                "CASE
                    WHEN LOWER(name) = ? THEN 0
                    WHEN LOWER(name) LIKE ? THEN 1
                    WHEN LOWER(slug) LIKE ? THEN 2
                    ELSE 3
                END",
                [$needle, $prefix, $prefix]
            )
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(function ($club) {
                return [
                    'id' => (int) $club->id,
                    'name' => (string) $club->name,
                    'slug' => (string) $club->slug,
                ];
            })
            ->values();

        return response()->json([
            'query' => $q,
            'players' => $players,
            'clubs' => $clubs,
        ]);
    }
}
