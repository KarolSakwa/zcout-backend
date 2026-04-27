<?php

namespace App\Support;

class OverallConfig
{
    public static function archetypeForPosition(?string $position): ?string
    {
        $position = strtoupper(trim((string) $position));

        if ($position === '') {
            return null;
        }

        return config("overall.position_to_archetype.$position");
    }

    public static function axisWeightsForArchetype(?string $archetype): array
    {
        $archetype = strtoupper(trim((string) $archetype));

        if ($archetype === '') {
            return [];
        }

        return config("overall.archetype_axis_weights.$archetype", []);
    }

    public static function numericWeights(): array
    {
        return config('overall.weights', []);
    }

    public static function forPosition(?string $position): array
    {
        $archetype = self::archetypeForPosition($position);

        return [
            'archetype' => $archetype,
            'axis_weights' => self::axisWeightsForArchetype($archetype),
            'weights' => self::numericWeights(),
        ];
    }

    public static function resolvedAxisWeightsForPosition(?string $position): array
    {
        $axisWeights = self::axisWeightsForArchetype(self::archetypeForPosition($position));

        return collect($axisWeights)
            ->filter(fn ($w) => is_numeric($w))
            ->map(fn ($w) => (float) $w)
            ->all();
    }

    public static function overallFromRadarAxes(?string $position, array $radarAxes): ?float
    {
        $resolvedWeights = self::resolvedAxisWeightsForPosition($position);

        if ($resolvedWeights === []) {
            return null;
        }

        $valuesByAxis = collect($radarAxes)->mapWithKeys(
            fn (array $axis) => [(string) ($axis['key'] ?? '') => (float) ($axis['value'] ?? 0)]
        );

        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($resolvedWeights as $axisKey => $weight) {
            if (!$valuesByAxis->has($axisKey)) {
                continue;
            }

            $weightedSum += ((float) $valuesByAxis[$axisKey]) * (float) $weight;
            $weightSum += (float) $weight;
        }

        if ($weightSum <= 0) {
            return null;
        }

        return round($weightedSum, 2);
    }

    private static function buildAttributeAxisCountMap(array $axes): array
    {
        $map = [];

        foreach ($axes as $attributes) {
            foreach ($attributes as $attr) {
                if (!isset($map[$attr])) {
                    $map[$attr] = 0;
                }
                $map[$attr]++;
            }
        }

        return $map;
    }
}
