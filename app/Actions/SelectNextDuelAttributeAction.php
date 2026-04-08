<?php

namespace App\Actions;

use App\Models\Attribute;

final class SelectNextDuelAttributeAction
{
    public function handle(array $context): ?Attribute
    {
        $cfg = $context['cfg'] ?? [];
        $requestedAttribute = $context['requested_attribute'] ?? null;

        if ($requestedAttribute) {
            return Attribute::query()
                ->where('key', $requestedAttribute)
                ->first();
        }

        $scopeMix = $cfg['attribute_scope_mix'] ?? [
                'both' => 0.90,
                'gk' => 0.10,
            ];

        $gkShare = (float) ($scopeMix['gk'] ?? 0.10);
        if ($gkShare < 0.0) {
            $gkShare = 0.0;
        }
        if ($gkShare > 1.0) {
            $gkShare = 1.0;
        }

        $scope = (mt_rand() / mt_getrandmax()) < $gkShare ? 'gk' : 'both';

        $allowedKeys = $cfg['attribute_selection']['organic_allowed_keys'] ?? null;

        $query = Attribute::query()
            ->where('scope', $scope);

        if (is_array($allowedKeys) && $allowedKeys !== []) {
            $query->whereIn('key', $allowedKeys);
        }

        $attribute = $query
            ->inRandomOrder()
            ->first();

        if ($attribute) {
            return $attribute;
        }

        $fallbackQuery = Attribute::query();

        if (is_array($allowedKeys) && $allowedKeys !== []) {
            $fallbackQuery->whereIn('key', $allowedKeys);
        }

        return $fallbackQuery
            ->inRandomOrder()
            ->first();
    }
}
