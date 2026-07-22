<?php

namespace App\Actions\Rankings;

use App\Models\Attribute;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ResolveFeaturedRankingAttributeAction
{
    /**
     * @return Collection<int, Attribute>
     */
    public function eligibleAttributes(): Collection
    {
        return Attribute::query()
            ->where('key', '!=', 'overall')
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    public function execute(?CarbonInterface $date = null): ?Attribute
    {
        $attributes = $this->eligibleAttributes();

        if ($attributes->isEmpty()) {
            return null;
        }

        $date = ($date ?? now())->copy()->startOfDay();
        $dayIndex = intdiv((int) $date->timestamp, 86_400);
        $selectedIndex = $dayIndex % $attributes->count();

        return $attributes->get($selectedIndex);
    }
}
