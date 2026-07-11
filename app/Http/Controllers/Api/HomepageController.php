<?php

namespace App\Http\Controllers\Api;

use App\Actions\BuildFeaturedRankingPayloadAction;
use App\Http\Controllers\Controller;
use App\Support\Homepage\NeedsMoreRatingsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomepageController extends Controller
{
    public function featuredRanking(BuildFeaturedRankingPayloadAction $buildFeaturedRankingPayloadAction): JsonResponse
    {
        return response()->json($buildFeaturedRankingPayloadAction->execute());
    }

    public function needsMoreRatings(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 5), 10));

        $rows = DB::table('players as p')
            ->join('player_reputation_stats as prs', 'prs.player_id', '=', 'p.id')
            ->join('player_overalls as po', 'po.player_id', '=', 'p.id')
            ->leftJoin('clubs as c', 'c.id', '=', 'p.club_id')
            ->leftJoin('positions as manual_pos', 'manual_pos.id', '=', 'p.manual_position_id')
            ->leftJoin('positions as fd_pos', 'fd_pos.id', '=', 'p.fd_position_id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
            ->whereIn('prs.tier', ['A', 'B'])
            ->where('po.confidence', '>=', 5)
            ->orderBy('po.confidence')
            ->orderBy('p.id')
            ->limit($limit)
            ->get([
                'p.id as player_id',
                'p.slug as player_slug',
                DB::raw('COALESCE(p.manual_display_name, p.fd_name, p.name) as player_name'),
                DB::raw('COALESCE(c.name, p.club) as club_name'),
                DB::raw('COALESCE(manual_pos.short_label, fd_pos.short_label, pos.short_label) as position_short'),
                'po.overall',
                'po.confidence',
            ]);

        $items = $rows
            ->map(fn ($row) => NeedsMoreRatingsPayload::fromRow($row))
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }
}
