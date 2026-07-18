<?php

namespace App\Matchmaking;

use Illuminate\Support\Facades\DB;

final class MatchmakingCandidateRowFetcher
{
    public function handle(array $context)
    {
        $attributeId = (int) ($context['attribute_id'] ?? 0);
        $intent = (string) ($context['intent'] ?? '');
        $selectedTier = $context['selected_tier'] ?? null;
        $forceGK = (bool) ($context['force_gk'] ?? false);

        $rowsQ = DB::table('players as p')
            ->join('player_reputation_stats as prs', 'prs.player_id', '=', 'p.id')
            ->join('clubs as c', 'c.id', '=', 'p.club_id')
            ->leftJoin('positions as pos', function ($join) {
                $join->on('pos.id', '=', DB::raw('COALESCE(p.manual_position_id, p.fd_position_id, p.position_id)'));
            })
            ->leftJoin('player_attribute_ratings as par', function ($join) use ($attributeId) {
                $join->on('par.player_id', '=', 'p.id')
                    ->where('par.attribute_id', '=', $attributeId);
            })
            ->whereNotNull('p.club_id')
            ->where('c.is_current_premier_league', '=', true)
            ->whereNotNull(DB::raw('COALESCE(p.manual_position_id, p.fd_position_id, p.position_id)'));

        if ($intent === 'production' && $selectedTier !== null) {
            $rowsQ->where('prs.tier', '=', $selectedTier);
        }

        if ($forceGK) {
            $rowsQ->where('pos.short_label', '=', 'GK');
        } else {
            $rowsQ->where('pos.short_label', '!=', 'GK');
        }

        return $rowsQ
            ->selectRaw('p.id, pos.short_label as pos_short, prs.player_rep, (COALESCE(par.confidence, 0) / 100.0) as attr_confidence, par.rating as attr_rating, COALESCE(prs.fpl_now_cost, 0) as fpl_cost')
            ->get();
    }
}
