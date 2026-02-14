<?php

namespace App\Support;

final class Seed
{
    private const POS_TO_GROUP = [
        'GK' => 'GK',

        'CB' => 'DEF',
        'LB' => 'DEF',
        'RB' => 'DEF',
        'DEF' => 'DEF',

        'DM' => 'MID',
        'CM' => 'MID',
        'AM' => 'MID',
        'LM' => 'MID',
        'RM' => 'MID',
        'MID' => 'MID',

        'LW' => 'ATT',
        'RW' => 'ATT',
        'ST' => 'ATT',
        'ATT' => 'ATT',
    ];

    public static function for(string $position, string $attributeKey): float
    {
        $default = (float) config('zcout_seeds.default_seed', 65.0);
        $matrix = (array) config('zcout_seeds.matrix', []);

        $pos = strtoupper(trim($position));
        $attr = strtolower(trim($attributeKey)); // DB: pace, first_touch, work_rate...

        if ($pos === '' || $attr === '') {
            return $default;
        }

        if (isset($matrix[$pos]) && array_key_exists($attr, $matrix[$pos])) {
            return (float) $matrix[$pos][$attr];
        }

        $group = self::POS_TO_GROUP[$pos] ?? null;
        if ($group && isset($matrix[$group]) && array_key_exists($attr, $matrix[$group])) {
            return (float) $matrix[$group][$attr];
        }

        return $default;
    }
}
