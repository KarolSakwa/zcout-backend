<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;
use App\Http\Resources\PlayerResource;

class PlayerController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'club' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $player = Player::create($data);

        return (new PlayerResource($player))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Player $player)
    {
        return new PlayerResource($player);
    }

}
