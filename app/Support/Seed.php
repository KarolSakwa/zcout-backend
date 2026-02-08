<?php

namespace App\Support;

final class Seed
{
    public static function for(?string $pos, ?string $attr): float
    {
        $default = (float) (config('zcout_seeds.default_seed') ?? 65);

        $p = strtoupper((string) $pos);
        $a = strtoupper((string) $attr);

        $matrix = config('zcout_seeds.matrix') ?? [];

        $v = $matrix[$p][$a] ?? null;

        return is_numeric($v) ? (float) $v : $default;
    }
}
