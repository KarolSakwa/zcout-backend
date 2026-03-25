<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Support\Seed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Support\OverallConfig;

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

        return response()->json(['id' => $player->id], 201);
    }

    public function show(Player $player)
    {
        $player = Player::query()
            ->select('id', 'name', 'slug', 'club_id', 'country_id', 'position_id', 'number', 'date_of_birth')
            ->with([
                'clubRel:id,name,slug,color_primary,color_secondary,color_tertiary',
                'countryRef:id,name,iso2',
                'positionRef:id,short_label',
            ])
            ->whereKey($player->id)
            ->firstOrFail();

        $posCode = strtoupper((string) ($player->positionRef?->short_label ?? ''));

        $attributes = Attribute::query()
            ->select('id', 'key', 'label', 'group')
            ->orderBy('key')
            ->get();

        $rows = PlayerAttributeRating::query()
            ->where('player_id', $player->id)
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get()
            ->keyBy('attribute_id');

        $payloadAttrs = [];
        $totalWeight = 0.0;

        foreach ($attributes as $attr) {
            $row = $rows->get($attr->id);

            $rating = $row ? (float) $row->rating : (float) Seed::for($posCode, $attr->key);
            $confidence = $row ? (float) ($row->confidence ?? 0) : 0.0;
            $weightSum = $row ? (float) ($row->weight_sum ?? 0) : 0.0;
            $votesCount = $row ? (int) ($row->votes_count ?? 0) : 0;
            $lastVoteAt = $row ? ($row->last_vote_at ? (string) $row->last_vote_at : null) : null;

            $payloadAttrs[] = [
                'id' => (int) $attr->id,
                'key' => (string) $attr->key,
                'label' => (string) $attr->label,
                'group' => $attr->group,
                'rating' => (float) $rating,
                'confidence' => (float) min(100.0, round($confidence, 2)),
                'weight_sum' => (float) $weightSum,
                'votes_count' => (int) $votesCount,
                'last_vote_at' => $lastVoteAt,
            ];

            $totalWeight += $weightSum;
        }

        $overallConfidence = (float) min(100.0, round($totalWeight, 2));
        $radarAxes = $this->buildRadarAxesPayload($posCode, $payloadAttrs);
        $overall = OverallConfig::overallFromRadarAxes($posCode, $radarAxes);

        return response()->json([
            'id' => (int) $player->id,
            'name' => (string) $player->name,
            'slug' => $player->slug,
            'number' => $player->number,
            'date_of_birth' => $player->date_of_birth,
            'position' => $player->positionRef?->short_label,
            'club' => $player->clubRel ? [
                'id' => (int) $player->clubRel->id,
                'name' => (string) $player->clubRel->name,
                'slug' => (string) $player->clubRel->slug,
                'color_primary' => $player->clubRel->color_primary,
                'color_secondary' => $player->clubRel->color_secondary,
                'color_tertiary' => $player->clubRel->color_tertiary,
            ] : null,
            'country' => $player->countryRef ? [
                'id' => (int) $player->countryRef->id,
                'name' => (string) $player->countryRef->name,
                'iso2' => $player->countryRef->iso2,
            ] : null,
            'overall_confidence' => $overallConfidence,
            'radar_axes' => $radarAxes,
            'attributes' => $payloadAttrs,
            'overall' => $overall,
        ]);
    }

    private function buildRadarAxesPayload(string $posCode, array $payloadAttrs): array
    {
        $axisConfigKey = $posCode === 'GK'
            ? 'zcout_attributes.gk_axes'
            : 'zcout_attributes.outfield_axes';

        $ratingsByKey = collect($payloadAttrs)
            ->mapWithKeys(fn (array $attr) => [
                (string) $attr['key'] => (float) $attr['rating'],
            ]);

        return collect(config($axisConfigKey, []))
            ->map(function (array $attributeKeys, string $key) use ($ratingsByKey) {
                $values = collect($attributeKeys)
                    ->map(fn (string $attributeKey) => $ratingsByKey->get($attributeKey))
                    ->filter(fn ($value) => is_numeric($value))
                    ->values();

                $value = $values->isNotEmpty()
                    ? round((float) $values->avg(), 1)
                    : 0.0;

                return [
                    'key' => $key,
                    'label' => Str::of($key)->replace('_', ' ')->upper()->toString(),
                    'attribute_keys' => $attributeKeys,
                    'value' => (float) $value,
                ];
            })
            ->values()
            ->all();
    }
}
