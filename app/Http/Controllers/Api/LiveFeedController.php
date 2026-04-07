<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Live\TopMoverItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Live\RecentVoteItem;

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
            ->where('v.source', 'duel')
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->limit($limit)
            ->get([
                'v.id',
                'v.winner_id',
                'v.player_a_id',
                'v.player_b_id',
                'winner_player.name as winner_name',
                'player_a.name as player_a_name',
                'player_b.name as player_b_name',
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
            ->where('v.source', 'duel')
            ->where('v.created_at', '>=', $since)
            ->whereNotNull('v.pre_rating_a')
            ->whereNotNull('v.post_rating_a')
            ->whereNotNull('v.pre_rating_b')
            ->whereNotNull('v.post_rating_b')
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
            $deltaB = (float) $row->post_rating_b - (float) $row->pre_rating_b;

            $keyA = (int) $row->player_a_id . ':' . (int) $row->attribute_id;
            $keyB = (int) $row->player_b_id . ':' . (int) $row->attribute_id;

            if (!isset($aggregated[$keyA])) {
                $aggregated[$keyA] = [
                    'playerId' => (int) $row->player_a_id,
                    'attributeId' => (int) $row->attribute_id,
                    'attributeKey' => (string) $row->attribute_key,
                    'attributeLabel' => (string) $row->attribute_label,
                    'deltaValue' => 0.0,
                ];
            }

            if (!isset($aggregated[$keyB])) {
                $aggregated[$keyB] = [
                    'playerId' => (int) $row->player_b_id,
                    'attributeId' => (int) $row->attribute_id,
                    'attributeKey' => (string) $row->attribute_key,
                    'attributeLabel' => (string) $row->attribute_label,
                    'deltaValue' => 0.0,
                ];
            }

            $aggregated[$keyA]['deltaValue'] += $deltaA;
            $aggregated[$keyB]['deltaValue'] += $deltaB;
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

        $aggregated = array_slice($aggregated, 0, $limit);

        $playerNamesById = DB::table('players')
            ->whereIn('id', array_column($aggregated, 'playerId'))
            ->pluck('name', 'id');

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
}
