<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;

class DuelController extends Controller
{
    public function next()
    {
        $requested = request('attribute');

        $attribute = $requested
            ? Attribute::where('key', $requested)->first()
            : Attribute::inRandomOrder()->first();

        if (!$attribute) {
            return response()->json(['error' => 'Unknown attribute'], 422);
        }

        $players = Player::query()
            ->select(['id', 'name', 'slug', 'number', 'club_id', 'country_id', 'position_id'])
            ->with([
                'clubRel:id,name,color_primary,color_secondary,color_tertiary',
                'countryRef:id,name,iso2',
                'positionRef:id,short_label,label,key',
            ])
            ->inRandomOrder()
            ->limit(2)
            ->get();

        if ($players->count() < 2) {
            return response()->json(['error' => 'Not enough players'], 422);
        }

        $playerA = min($players[0]->id, $players[1]->id);
        $playerB = max($players[0]->id, $players[1]->id);

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id'  => $playerA,
            'player_b_id'  => $playerB,
        ]);

        $toApi = function (Player $p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'number' => $p->number,

                'position' => $p->positionRef?->short_label
                    ?? $p->positionRef?->key
                    ?? $p->positionRef?->label
                    ?? null,

                'country' => $p->countryRef ? [
                    'id' => $p->countryRef->id,
                    'name' => $p->countryRef->name,
                    'iso2' => $p->countryRef->iso2,
                ] : null,

                'club' => $p->clubRel ? [
                    'name' => $p->clubRel->name,
                    'color_primary' => $p->clubRel->color_primary,
                    'color_secondary' => $p->clubRel->color_secondary,
                    'color_tertiary' => $p->clubRel->color_tertiary,
                ] : null,
            ];
        };

        return response()->json([
            'attribute' => [
                'id' => $attribute->id,
                'key' => $attribute->key,
                'label' => $attribute->label,
                'group' => $attribute->group,
            ],
            'players' => [$toApi($players[0]), $toApi($players[1])],
            'duel_id' => $duel->id,
        ]);
    }
}
