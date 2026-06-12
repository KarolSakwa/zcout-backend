<?php

namespace App\Actions;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

class BuildPlayerArchetypeFingerprintAction
{
    public function execute(Player $player): array
    {
        $ratings = DB::table('player_attribute_ratings as par')
            ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
            ->where('par.player_id', $player->id)
            ->where('par.confidence', '>=', 5)
            ->select('a.key', 'par.rating')
            ->orderBy('a.key')
            ->get();

        $attributes = $ratings
            ->mapWithKeys(fn ($rating) => [
                $rating->key => $this->bucketRating($rating->rating),
            ])
            ->toArray();

        if (count($attributes) < 5) {
            return [
                'payload' => null,
                'hash' => null,
            ];
        }

        $payload = [
            'version' => 1,
            'position' => $player->effective_position_key,
            'attributes' => $attributes,
        ];

        return [
            'payload' => $payload,
            'hash' => hash('sha256', json_encode($payload)),
        ];
    }

    private function bucketRating(float|int $rating): int
    {
        return (int) (floor($rating / 5) * 5);
    }
}
