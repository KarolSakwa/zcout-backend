<?php

namespace App\Support;

use Illuminate\Support\Str;

class RadarAxesBuilder
{
    public static function build(string $posCode, array $payloadAttrs): array
    {
        $axes = $posCode === 'GK'
            ? config('zcout_attributes.gk_axes')
            : config('zcout_attributes.outfield_axes');

        $attributeAxisCount = [];

        foreach ($axes as $attributes) {
            foreach ($attributes as $attr) {
                if (!isset($attributeAxisCount[$attr])) {
                    $attributeAxisCount[$attr] = 0;
                }
                $attributeAxisCount[$attr]++;
            }
        }

        $ratingsByKey = collect($payloadAttrs)
            ->mapWithKeys(fn (array $attr) => [
                (string) $attr['key'] => (float) $attr['rating'],
            ]);

        return collect($axes)
            ->map(function (array $attributeKeys, string $key) use ($ratingsByKey, $attributeAxisCount) {

                $weightedSum = 0.0;
                $weightSum = 0.0;

                foreach ($attributeKeys as $attributeKey) {
                    $raw = $ratingsByKey->get($attributeKey);

                    if (!is_numeric($raw)) {
                        continue;
                    }

                    $count = $attributeAxisCount[$attributeKey] ?? 1;
                    $weight = 1 / $count;

                    $weightedSum += ((float) $raw) * $weight;
                    $weightSum += $weight;
                }

                $value = $weightSum > 0
                    ? round($weightedSum / $weightSum, 1)
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
