<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
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


        $players = Player::inRandomOrder()->limit(2)->get();
        if ($players->count() < 2) {
            return response()->json(['error' => 'Not enough players'], 422);
        }

        // Minimalny payload na start (bez Resource, żeby szybciej ruszyć)
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
                    'club' => $players[0]->club,
                    'position' => $players[0]->position,
                ],
                [
                    'id' => $players[1]->id,
                    'name' => $players[1]->name,
                    'slug' => $players[1]->slug,
                    'country' => $players[1]->country,
                    'club' => $players[1]->club,
                    'position' => $players[1]->position,
                ],
            ],
        ]);
    }
}
