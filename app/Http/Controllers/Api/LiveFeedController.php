<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
