<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoteController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'attribute_key' => ['required', 'string', Rule::exists('attributes', 'key')],
            'player_a_id' => ['required', 'integer', Rule::exists('players', 'id')],
            'player_b_id' => ['required', 'integer', Rule::exists('players', 'id'), 'different:player_a_id'],
            'winner_id' => ['required', 'integer', Rule::in([$request->input('player_a_id'), $request->input('player_b_id')])],
            'voter_hash' => ['nullable', 'string', 'max:255'],
        ]);

        $attributeId = Attribute::where('key', $data['attribute_key'])->value('id');

        $vote = Vote::create([
            'attribute_id' => $attributeId,
            'player_a_id' => $data['player_a_id'],
            'player_b_id' => $data['player_b_id'],
            'winner_id' => $data['winner_id'],
            'voter_hash' => $data['voter_hash'] ?? null,
        ]);

        return response()->json(['id' => $vote->id], 201);
    }
}
