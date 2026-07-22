<?php

namespace App\Actions\Players;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class BuildPlayerArchetypeInputSnapshotAction
{
    public function execute(Player $player): ?array
    {
        $attributes = DB::table('player_attribute_ratings as par')
            ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
            ->where('par.player_id', $player->id)
            ->where('par.confidence', '>=', 5)
            ->select('a.key', 'a.label', 'a.group', 'par.rating', 'par.confidence')
            ->orderBy('a.key')
            ->get()
            ->map(fn ($attribute) => [
                'key' => $attribute->key,
                'label' => $attribute->label,
                'group' => $attribute->group,
                'rating' => (float) $attribute->rating,
                'confidence' => (float) $attribute->confidence,
            ])
            ->values()
            ->toArray();

        if (count($attributes) < 5) {
            return null;
        }

        return [
            'player' => [
                'position' => $player->effective_position_key,
                'overall' => null,
            ],
            'attributes' => $attributes,
        ];
    }
}
