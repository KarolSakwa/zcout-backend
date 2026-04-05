<?php

namespace App\Matchmaking;

final class MatchmakingCandidatePoolBuilder
{
    public function handle(array $context): array
    {
        $rows = $context['rows'] ?? [];
        $needPow = (float) ($context['need_pow'] ?? 1.0);

        $candidates = [];
        $maxCost = 0;
        $maxSel = 0.0;

        foreach ($rows as $r) {
            $posShort = $r->pos_short ? (string) $r->pos_short : null;
            if (!$posShort) {
                continue;
            }

            $rep = (float) $r->player_rep;
            $conf = (float) $r->attr_confidence;

            $need = 1.0 - $conf;
            if ($need < 0) {
                $need = 0.0;
            }
            if ($need > 1) {
                $need = 1.0;
            }

            $w = pow(max($need, 0.000001), $needPow);

            if ($w <= 0) {
                continue;
            }

            $cost = (int) ($r->fpl_cost ?? 0);
            $sel = (float) ($r->fpl_sel ?? 0);

            if ($cost > $maxCost) {
                $maxCost = $cost;
            }

            if ($sel > $maxSel) {
                $maxSel = $sel;
            }

            $candidates[] = [
                'id' => (int) $r->id,
                'pos' => $posShort,
                'line' => $this->posLine($posShort),
                'rep' => $rep,
                'conf' => $conf,
                'rating' => $r->attr_rating !== null ? (float) $r->attr_rating : null,
                'cost' => $cost,
                'sel' => $sel,
                'w' => (float) $w,
            ];
        }

        return [
            'candidates' => $candidates,
            'max_cost' => $maxCost,
            'max_sel' => $maxSel,
        ];
    }

    private function posLine(string $posShort): string
    {
        $p = strtoupper(trim($posShort));

        if ($p === 'GK') {
            return 'GK';
        }

        $def = ['CB', 'LB', 'RB', 'LWB', 'RWB', 'WB'];
        if (in_array($p, $def, true)) {
            return 'DEF';
        }

        $fwd = ['ST', 'CF', 'LW', 'RW', 'LF', 'RF', 'ATT'];
        if (in_array($p, $fwd, true)) {
            return 'FWD';
        }

        return 'MID';
    }
}
