<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesCurrentPremierLeagueClub
{
    protected function createCurrentPremierLeagueClub(string $name = 'Test Club', ?string $slug = null, ?int $externalId = null): int
    {
        return (int) DB::table('clubs')->insertGetId([
            'name' => $name,
            'slug' => $slug ?? str($name)->slug()->toString(),
            'external_id' => $externalId,
            'is_current_premier_league' => true,
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
