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

        $gkShare = max(0.0, min(1.0, (float) ($scopeMix['gk'] ?? 0.10)));
        $scope = (mt_rand() / mt_getrandmax()) < $gkShare ? 'gk' : 'both';

        return Attribute::query()
            ->where('scope', $scope)
            ->inRandomOrder()
            ->first();
    }
}
