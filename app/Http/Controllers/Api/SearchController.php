<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
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
                'players.slug',
                'players.position_id',
                'clubs.name as club_name',
                'positions.short_label as position'
            )
            ->leftJoin('clubs', 'clubs.id', '=', 'players.club_id')
            ->leftJoin('positions', 'positions.id', '=', 'players.position_id')
            ->where(function ($query) use ($contains) {
                $query
                    ->whereRaw('LOWER(players.name) LIKE ?', [$contains])
                    ->orWhereRaw('LOWER(players.slug) LIKE ?', [$contains]);
            })
            ->orderByRaw(
                "CASE
                    WHEN LOWER(players.name) = ? THEN 0
                    WHEN LOWER(players.name) LIKE ? THEN 1
                    WHEN LOWER(players.slug) LIKE ? THEN 2
                    ELSE 3
                END",
                [$needle, $prefix, $prefix]
            )
            ->orderBy('players.name')
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

                $sum = 0.0;
                $count = 0;

                foreach ($attributes as $attr) {
                    $aid = (int) $attr->id;
                    $key = $attributeKeysById[$aid] ?? null;
                    if ($key === null) {
                        continue;
                    }

                    $rating = $ratingsByPlayer[$pid][$aid] ?? (float) Seed::for($posCode, $key);
                    $sum += $rating;
                    $count++;
                }

                $overall = $count > 0 ? (int) round($sum / $count) : null;

                return [
                    'id' => $pid,
                    'name' => (string) $player->name,
                    'slug' => $player->slug ? (string) $player->slug : null,
                    'position' => $player->position ? (string) $player->position : null,
                    'club' => $player->club_name ? (string) $player->club_name : null,
                    'overall' => $overall,
                ];
            })
            ->values();

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
