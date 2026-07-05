<?php

namespace App\Http\Controllers\Api;

use App\Actions\BuildPlayerProfilePayloadAction;
use App\Actions\ResolveFeaturedPlayerAction;
use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function __construct(
        private readonly BuildPlayerProfilePayloadAction $buildPlayerProfilePayloadAction,
        private readonly ResolveFeaturedPlayerAction $resolveFeaturedPlayerAction,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'club' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $player = Player::create($data);

        return response()->json(['id' => $player->id], 201);
    }

    public function featured()
    {
        $featured = $this->resolveFeaturedPlayerAction->execute();

        return response()->json(
            $this->buildPlayerProfilePayloadAction->execute($featured['player'], $featured['rank'])
        );
    }

    public function show(Player $player, ?int $rank = null)
    {
        return response()->json(
            $this->buildPlayerProfilePayloadAction->execute($player, $rank)
        );
    }
}
