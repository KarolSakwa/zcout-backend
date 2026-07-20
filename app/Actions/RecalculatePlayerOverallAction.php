<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\PlayerOverall;
use App\Support\OverallConfidence;
use App\Support\OverallConfig;
use App\Support\RadarAxesBuilder;
use App\Support\Seed;
use App\Events\PlayerOverallUpdated;

class RecalculatePlayerOverallAction
{
    public function execute(Player $player): void
    {
        $pos = strtoupper((string) ($player->effective_position_short ?? ''));

        $attributeKeys = collect($pos === 'GK'
            ? config('zcout_attributes.gk', [])
            : config('zcout_attributes.outfield', [])
        )->pluck('key');

        $attributes = Attribute::query()
            ->select('id', 'key')
            ->whereIn('key', $attributeKeys)
            ->get();

        $rows = PlayerAttributeRating::query()
            ->where('player_id', $player->id)
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get()
            ->keyBy('attribute_id');

        $payloadAttrs = [];

        foreach ($attributes as $attr) {
            $row = $rows->get($attr->id);

            $rating = $row
                ? (float) $row->rating
                : (float) Seed::for($pos, $attr->key);

            $payloadAttrs[] = [
                'key' => (string) $attr->key,
                'rating' => $rating,
                'confidence' => (float) ($row?->confidence ?? 0),
            ];
        }

        $radarAxes = RadarAxesBuilder::build($pos, $payloadAttrs);

        $overall = OverallConfig::overallFromRadarAxes($pos, $radarAxes);

        $confidence = OverallConfidence::fromAttributePayload($payloadAttrs);

        PlayerOverall::updateOrCreate(
            [
                'player_id' => $player->id,
            ],
            [
                'position' => $pos,
                'overall' => round((float) ($overall ?? 0), 2),
                'confidence' => round((float) $confidence, 2),
            ]
        );

        event(new PlayerOverallUpdated(
            playerId: $player->id,
            overall: round((float) ($overall ?? 0), 2),
            confidence: round((float) $confidence, 2),
        ));
    }
}
