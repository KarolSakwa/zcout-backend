<?php

namespace App\Support\Live;

class TopMoverItem
{
    public static function fromArray(array $item, string $playerName): array
    {
        return [
            'id' => (string) ($item['playerId'] . ':' . $item['attributeKey']),
            'playerId' => (int) $item['playerId'],
            'player' => $playerName,
            'attributeKey' => (string) $item['attributeKey'],
            'attributeLabel' => (string) $item['attributeLabel'],
            'delta' => self::formatDelta((float) $item['deltaValue']),
        ];
    }

    private static function formatDelta(float $delta): string
    {
        $rounded = round($delta, 3);

        if ($rounded > 0) {
            return '+' . number_format($rounded, 3, '.', '');
        }

        return number_format($rounded, 3, '.', '');
    }
}
