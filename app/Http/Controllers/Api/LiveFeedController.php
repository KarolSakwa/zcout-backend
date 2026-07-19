<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Live\TopMoverItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Live\RecentVoteItem;
use Illuminate\Support\Facades\Cache;

class LiveFeedController extends Controller
{
    public function recentVotes(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 8), 20));

        $rows = DB::table('votes as v')
            ->join('players as winner_player', 'winner_player.id', '=', 'v.winner_id')
            ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->join('players as player_a', 'player_a.id', '=', 'v.player_a_id')
            ->join('players as player_b', 'player_b.id', '=', 'v.player_b_id')
            ->join('clubs as club_a', 'club_a.id', '=', 'player_a.club_id')
            ->join('clubs as club_b', 'club_b.id', '=', 'player_b.club_id')
            ->where('v.source', 'duel')
            ->where('club_a.is_current_premier_league', true)
            ->where('club_b.is_current_premier_league', true)
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->limit($limit)
            ->get([
                'v.id',
                'v.winner_id',
                'v.player_a_id',
                'v.player_b_id',
                DB::raw('COALESCE(winner_player.manual_display_name, winner_player.fd_name, winner_player.name) as winner_name'),
                DB::raw('COALESCE(player_a.manual_display_name, player_a.fd_name, player_a.name) as player_a_name'),
                DB::raw('COALESCE(player_b.manual_display_name, player_b.fd_name, player_b.name) as player_b_name'),
                'a.key as attribute_key',
                'a.label as attribute_label',
            ]);

        $items = $rows
            ->map(fn ($row) => RecentVoteItem::fromRow($row))
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function topMovers(Request $request): JsonResponse
    {
        $direction = $request->query('direction', 'risers');
        $direction = in_array($direction, ['risers', 'fallers'], true) ? $direction : 'risers';

        $period = $request->query('period', '7d');
        $limit = max(1, min((int) $request->integer('limit', 5), 10));

        $since = match ($period) {
            '7d' => now()->subDays(7),
            default => now()->subDays(7),
        };

        $rows = DB::table('votes as v')
            ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->where('v.created_at', '>=', $since)
            ->whereNotNull('v.pre_rating_a')
            ->whereNotNull('v.post_rating_a')
            ->orderByDesc('v.created_at')
            ->get([
                'v.attribute_id',
                'v.player_a_id',
                'v.player_b_id',
                'v.pre_rating_a',
                'v.post_rating_a',
                'v.pre_rating_b',
                'v.post_rating_b',
                'a.key as attribute_key',
                'a.label as attribute_label',
            ]);

        $aggregated = [];

        foreach ($rows as $row) {
            $deltaA = (float) $row->post_rating_a - (float) $row->pre_rating_a;
            $keyA = (int) $row->player_a_id . ':' . (int) $row->attribute_id;

            if (!isset($aggregated[$keyA])) {
                $aggregated[$keyA] = [
                    'playerId' => (int) $row->player_a_id,
                    'attributeId' => (int) $row->attribute_id,
                    'attributeKey' => (string) $row->attribute_key,
                    'attributeLabel' => (string) $row->attribute_label,
                    'deltaValue' => 0.0,
                ];
            }

            $aggregated[$keyA]['deltaValue'] += $deltaA;

            if (
                $row->player_b_id !== null &&
                $row->pre_rating_b !== null &&
                $row->post_rating_b !== null
            ) {
                $deltaB = (float) $row->post_rating_b - (float) $row->pre_rating_b;
                $keyB = (int) $row->player_b_id . ':' . (int) $row->attribute_id;

                if (!isset($aggregated[$keyB])) {
                    $aggregated[$keyB] = [
                        'playerId' => (int) $row->player_b_id,
                        'attributeId' => (int) $row->attribute_id,
                        'attributeKey' => (string) $row->attribute_key,
                        'attributeLabel' => (string) $row->attribute_label,
                        'deltaValue' => 0.0,
                    ];
                }

                $aggregated[$keyB]['deltaValue'] += $deltaB;
            }
        }

        $aggregated = array_values(array_filter($aggregated, function (array $item) use ($direction) {
            return $direction === 'risers'
                ? $item['deltaValue'] > 0
                : $item['deltaValue'] < 0;
        }));

        usort($aggregated, function (array $a, array $b) use ($direction) {
            if ($direction === 'risers') {
                return $b['deltaValue'] <=> $a['deltaValue'];
            }

            return $a['deltaValue'] <=> $b['deltaValue'];
        });

        $activePlayerIds = DB::table('players as p')
            ->join('clubs as c', 'c.id', '=', 'p.club_id')
            ->where('c.is_current_premier_league', true)
            ->whereIn('p.id', array_column($aggregated, 'playerId'))
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $activePlayerIdSet = array_fill_keys($activePlayerIds, true);

        $aggregated = array_values(array_filter(
            $aggregated,
            fn (array $item) => isset($activePlayerIdSet[$item['playerId']])
        ));
        $aggregated = array_slice($aggregated, 0, $limit);

        $playerNamesById = DB::table('players')
            ->whereIn('id', array_column($aggregated, 'playerId'))
            ->selectRaw('id, COALESCE(manual_display_name, fd_name, name) as effective_name')
            ->pluck('effective_name', 'id');

        $items = array_map(function (array $item) use ($playerNamesById) {
            return TopMoverItem::fromArray(
                $item,
                (string) ($playerNamesById[$item['playerId']] ?? 'Unknown')
            );
        }, $aggregated);

        return response()->json([
            'items' => array_values($items),
        ]);
    }

    public function topMoversSummary(Request $request): JsonResponse
    {
        $period = $request->query('period', '7d');
        $limit = max(1, min((int) $request->integer('limit', 5), 10));

        $cacheKey = "live:top-movers-summary:{$period}:{$limit}";

        $payload = Cache::remember($cacheKey, now()->addSeconds(5), function () use ($period, $limit) {
            $since = match ($period) {
                '7d' => now()->subDays(7),
                default => now()->subDays(7),
            };

            $baseRows = DB::query()
                ->fromSub(function ($query) use ($since) {
                    $query->from('votes as v')
                        ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
                        ->where('v.created_at', '>=', $since)
                        ->whereNotNull('v.pre_rating_a')
                        ->whereNotNull('v.post_rating_a')
                        ->whereNotNull('v.pre_rating_b')
                        ->whereNotNull('v.post_rating_b')
                        ->selectRaw('v.player_a_id as player_id, v.attribute_id, a.key as attribute_key, a.label as attribute_label, (v.post_rating_a::numeric - v.pre_rating_a::numeric) as delta_value')
                        ->unionAll(
                            DB::table('votes as v')
                                ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
                                ->where('v.created_at', '>=', $since)
                                ->whereNotNull('v.pre_rating_a')
                                ->whereNotNull('v.post_rating_a')
                                ->whereNotNull('v.pre_rating_b')
                                ->whereNotNull('v.post_rating_b')
                                ->selectRaw('v.player_b_id as player_id, v.attribute_id, a.key as attribute_key, a.label as attribute_label, (v.post_rating_b::numeric - v.pre_rating_b::numeric) as delta_value')
                        );
                }, 'movers')
                ->join('players as p', 'p.id', '=', 'movers.player_id')
                ->join('clubs as c', 'c.id', '=', 'p.club_id')
                ->where('c.is_current_premier_league', true)
                ->selectRaw('movers.player_id as "playerId", movers.attribute_id as "attributeId", movers.attribute_key as "attributeKey", movers.attribute_label as "attributeLabel", SUM(movers.delta_value) as "deltaValue"')
                ->groupBy('movers.player_id', 'movers.attribute_id', 'movers.attribute_key', 'movers.attribute_label');

            $risersRaw = (clone $baseRows)
                ->havingRaw('SUM(delta_value) > 0')
                ->orderByDesc('deltaValue')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $fallersRaw = (clone $baseRows)
                ->havingRaw('SUM(delta_value) < 0')
                ->orderBy('deltaValue')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $playerIds = array_values(array_unique(array_merge(
                array_column($risersRaw, 'playerId'),
                array_column($fallersRaw, 'playerId'),
            )));

            $playerNamesById = empty($playerIds)
                ? collect()
                : DB::table('players')
                    ->whereIn('id', $playerIds)
                    ->selectRaw('id, COALESCE(manual_display_name, fd_name, name) as effective_name')
                    ->pluck('effective_name', 'id');

            return [
                'risers' => array_map(function (array $item) use ($playerNamesById) {
                    return TopMoverItem::fromArray(
                        $item,
                        (string) ($playerNamesById[$item['playerId']] ?? 'Unknown')
                    );
                }, $risersRaw),
                'fallers' => array_map(function (array $item) use ($playerNamesById) {
                    return TopMoverItem::fromArray(
                        $item,
                        (string) ($playerNamesById[$item['playerId']] ?? 'Unknown')
                    );
                }, $fallersRaw),
            ];
        });

        return response()->json($payload);
    }

    private function pickTopMovers(array $aggregated, string $direction, int $limit): array
    {
        $filtered = array_values(array_filter($aggregated, function (array $item) use ($direction) {
            return $direction === 'risers'
                ? $item['deltaValue'] > 0
                : $item['deltaValue'] < 0;
        }));

        usort($filtered, function (array $a, array $b) use ($direction) {
            if ($direction === 'risers') {
                return $b['deltaValue'] <=> $a['deltaValue'];
            }

            return $a['deltaValue'] <=> $b['deltaValue'];
        });

        return array_slice($filtered, 0, $limit);
    }
}
