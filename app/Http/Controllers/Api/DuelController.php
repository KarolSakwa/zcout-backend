<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\Duel;

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


        $players = Player::with('clubRel')->inRandomOrder()->limit(2)->get();
        if ($players->count() < 2) {
            return response()->json(['error' => 'Not enough players'], 422);
        }

        $playerA = min($players[0]->id, $players[1]->id);
        $playerB = max($players[0]->id, $players[1]->id);

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id' => $playerA,
            'player_b_id' => $playerB,
        ]);

        return response()->json([
            'attribute' => [
                'id' => $attribute->id,
                'key' => $attribute->key,
                'label' => $attribute->label,
                'group' => $attribute->group,
            ],
            'players' => [
                [
                    'id' => $players[0]->id,
                    'name' => $players[0]->name,
                    'slug' => $players[0]->slug,
                    'country' => $players[0]->country,
                    'club' => $players[0]->clubRel ? [
                        'name' => $players[0]->clubRel->name,
                        'color_primary' => $players[0]->clubRel->color_primary,
                        'color_secondary' => $players[0]->clubRel->color_secondary,
                    ] : null,
                    'position' => $players[0]->position,
                    'number' => $players[0]->number,
                ],
                [
                    'id' => $players[1]->id,
                    'name' => $players[1]->name,
                    'slug' => $players[1]->slug,
                    'country' => $players[1]->country,
                    'club' => $players[1]->clubRel ? [
                        'name' => $players[1]->clubRel->name,
                        'color_primary' => $players[1]->clubRel->color_primary,
                        'color_secondary' => $players[1]->clubRel->color_secondary,
                    ] : null,
                    'position' => $players[1]->position,
                    'number' => $players[1]->number,
                ],
            ],
            'duel_id' => $duel->id,
        ]);
    }
}
