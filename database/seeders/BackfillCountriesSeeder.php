<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Player;
use Illuminate\Database\Seeder;

class BackfillCountriesSeeder extends Seeder
{
    public function run(): void
    {
        $codes = Player::query()
            ->whereNotNull('country')
            ->distinct()
            ->pluck('country')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()
            ->unique()
            ->values();

        foreach ($codes as $code) {
            Country::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'flag_url' => "/flags/" . strtolower($code) . ".png"]
            );
        }

        $map = Country::query()->pluck('id', 'code');

        Player::query()
            ->whereNotNull('country')
            ->orderBy('id')
            ->chunkById(200, function ($players) use ($map) {
                foreach ($players as $p) {
                    $code = strtoupper(trim((string) $p->country));
                    if (isset($map[$code])) {
                        $p->country_id = $map[$code];
                        $p->save();
                    }
                }
            });
    }
}
