<?php

namespace App\Actions;

use App\Models\Position;

final class BuildRankingMetaAction
{
    public function execute(): array
    {
        $mapAttributeOptions = function (array $items) {
            return collect($items)
                ->map(function (array $item) {
                    $key = (string) ($item['key'] ?? '');

                    return [
                        'value' => $key,
                        'label' => strtoupper((string) ($item['label'] ?? str_replace('_', ' ', $key))),
                    ];
                })
                ->sortBy(fn (array $item) => mb_strtolower($item['label']), SORT_NATURAL)
                ->prepend([
                    'value' => 'overall',
                    'label' => 'OVERALL',
                ])
                ->values();
        };

        $outfieldAttributes = $mapAttributeOptions(config('zcout_attributes.outfield', []));
        $gkAttributes = $mapAttributeOptions(config('zcout_attributes.gk', []));

        $positionOrder = ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'LM', 'RM', 'AM', 'LW', 'RW', 'ST'];

        $positionsFromDb = Position::query()
            ->select('short_label')
            ->whereIn('short_label', $positionOrder)
            ->get()
            ->map(fn ($p) => strtoupper((string) $p->short_label))
            ->all();

        $positions = collect($positionOrder)
            ->filter(fn (string $pos) => in_array($pos, $positionsFromDb, true))
            ->map(fn (string $pos) => [
                'value' => $pos,
                'label' => $pos,
            ])
            ->prepend([
                'value' => 'ALL',
                'label' => 'ALL POSITIONS',
            ])
            ->values();

        return [
            'positions' => $positions,
            'outfield_attributes' => $outfieldAttributes,
            'gk_attributes' => $gkAttributes,
        ];
    }
}
