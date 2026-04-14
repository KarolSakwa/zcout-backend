<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatabaseController extends Controller
{
    public function clubs(\Illuminate\Http\Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $clubs = DB::table('players as p')
            ->selectRaw('p.club as club, c.color_primary as primary, c.color_secondary as secondary, c.color_tertiary as tertiary')
            ->leftJoin('clubs as c', 'c.name', '=', 'p.club')
            ->whereNotNull('p.club')
            ->where('p.club', '!=', '')
            ->groupBy('p.club', 'c.color_primary', 'c.color_secondary', 'c.color_tertiary')
            ->orderBy('p.club')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                return [
                    'club' => (string) $r->club,
                    'colors' => [
                        'primary' => $r->primary ? (string) $r->primary : null,
                        'secondary' => $r->secondary ? (string) $r->secondary : null,
                        'tertiary' => $r->tertiary ? (string) $r->tertiary : null,
                    ],
                    'overall' => null,
                    'attack' => null,
                    'midfield' => null,
                    'defence' => null,
                ];
            })
            ->values();

        return response()->json([
            'filters' => [
                'limit' => $limit,
                'league' => 'Premier League',
            ],
            'items' => $clubs,
        ]);
    }

    public function club(string $slug, \Illuminate\Http\Request $request)
    {
        $limit = (int) $request->query('limit', 200);
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $club = DB::table('clubs')
            ->select('name', 'slug', 'color_primary', 'color_secondary', 'color_tertiary')
            ->where('slug', $slug)
            ->first();

        if (!$club) {
            return response()->json(['message' => 'Club not found.'], 404);
        }

        $coreKeys = [
            'pace',
            'dribbling',
            'passing',
            'vision',
            'tackling',
            'finishing',
            'first_touch',
            'technique',
            'stamina',
            'positioning',
        ];

        $attrs = \App\Models\Attribute::query()
            ->select('id', 'key')
            ->whereIn('key', $coreKeys)
            ->get();

        $keyToId = [];
        foreach ($attrs as $a) {
            $keyToId[$a->key] = (int) $a->id;
        }

        $attrIds = array_values($keyToId);

        $players = \App\Models\Player::query()
            ->select('id', 'name', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with(['positionRef:id,short_label', 'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label'])
            ->where('club', (string) $club->name)
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $playerIds = $players->pluck('id')->map(fn ($v) => (int) $v)->values()->all();

        $ratings = [];
        if (!empty($playerIds) && !empty($attrIds)) {
            $rows = \App\Models\PlayerAttributeRating::query()
                ->select('player_id', 'attribute_id', 'rating', 'confidence')
                ->whereIn('player_id', $playerIds)
                ->whereIn('attribute_id', $attrIds)
                ->get();

            foreach ($rows as $r) {
                $pid = (int) $r->player_id;
                $aid = (int) $r->attribute_id;
                if (!isset($ratings[$pid])) $ratings[$pid] = [];
                $ratings[$pid][$aid] = [
                    'rating' => (float) $r->rating,
                    'confidence' => (float) ($r->confidence ?? 0),
                ];
            }
        }

        $items = [];
        foreach ($players as $p) {
            $pid = (int) $p->id;
            $pos = strtoupper((string) ($p->effective_position_short ?? ''));

            $sum = 0.0;
            $confSum = 0.0;
            $n = 0;

            foreach ($coreKeys as $k) {
                $aid = $keyToId[$k] ?? null;

                $r = null;
                if ($aid && isset($ratings[$pid]) && isset($ratings[$pid][$aid])) {
                    $r = $ratings[$pid][$aid];
                }

                $sum += $r ? (float) $r['rating'] : (float) \App\Support\Seed::for($pos, $k);
                $confSum += $r ? (float) $r['confidence'] : 0.0;
                $n++;
            }

            $overall = $n > 0 ? (int) round($sum / $n) : null;
            $confidence = $n > 0 ? (int) round($confSum / $n) : 0;
            if ($confidence < 0) $confidence = 0;
            if ($confidence > 100) $confidence = 100;

            $items[] = [
                'id' => $pid,
                'name' => (string) $p->effective_name,
                'pos' => $pos,
                'overall' => $overall,
                'confidence' => $confidence,
            ];
        }

        $attackSet = ['ST', 'LW', 'RW', 'ATT'];
        $midSet = ['AM', 'CM', 'DM', 'LM', 'RM', 'MID'];
        $defSet = ['CB', 'LB', 'RB', 'DEF', 'GK'];

        $sumAll = 0.0;
        $cntAll = 0;

        $sumA = 0.0; $cntA = 0;
        $sumM = 0.0; $cntM = 0;
        $sumD = 0.0; $cntD = 0;

        $top = null;

        foreach ($items as $it) {
            if ($it['overall'] === null) continue;

            $sumAll += $it['overall'];
            $cntAll++;

            if (in_array($it['pos'], $attackSet, true)) { $sumA += $it['overall']; $cntA++; }
            if (in_array($it['pos'], $midSet, true)) { $sumM += $it['overall']; $cntM++; }
            if (in_array($it['pos'], $defSet, true)) { $sumD += $it['overall']; $cntD++; }

            if ($top === null) {
                $top = $it;
            } else {
                if ($it['overall'] > $top['overall']) $top = $it;
                elseif ($it['overall'] === $top['overall'] && $it['confidence'] > $top['confidence']) $top = $it;
            }
        }

        $stats = [
            'overall' => $cntAll > 0 ? (int) round($sumAll / $cntAll) : null,
            'attack' => $cntA > 0 ? (int) round($sumA / $cntA) : null,
            'midfield' => $cntM > 0 ? (int) round($sumM / $cntM) : null,
            'defence' => $cntD > 0 ? (int) round($sumD / $cntD) : null,
        ];

        return response()->json([
            'club' => [
                'name' => (string) $club->name,
                'slug' => (string) $club->slug,
                'colors' => [
                    'primary' => $club->color_primary ? (string) $club->color_primary : null,
                    'secondary' => $club->color_secondary ? (string) $club->color_secondary : null,
                    'tertiary' => $club->color_tertiary ? (string) $club->color_tertiary : null,
                ],
                'stats' => $stats,
                'top_player' => $top,
            ],
            'filters' => [
                'limit' => $limit,
            ],
            'items' => $items,
        ]);
    }
}
