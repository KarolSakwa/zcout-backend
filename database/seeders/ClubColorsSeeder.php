<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClubColorsSeeder extends Seeder
{
    public function run(): void
    {
        $clubs = [
            ['nameLike' => 'Arsenal%',                  'p' => '#EF0107', 's' => '#FFFFFF', 't' => '#023474'],
            ['nameLike' => 'Aston Villa%',              'p' => '#660033', 's' => '#94BEE5', 't' => '#FFFFFF'],
            ['nameLike' => '%Bournemouth%',             'p' => '#DA291C', 's' => '#000000', 't' => '#FFFFFF'],
            ['nameLike' => 'Brentford%',                'p' => '#D20000', 's' => '#FFFFFF', 't' => '#000000'],
            ['nameLike' => 'Brighton%',                 'p' => '#0057B8', 's' => '#FFFFFF', 't' => '#FFCD00'],
            ['nameLike' => 'Burnley%',                  'p' => '#6C1D45', 's' => '#99D6EA', 't' => '#FFFFFF'],
            ['nameLike' => 'Chelsea%',                  'p' => '#153D8A', 's' => '#FFFFFF', 't' => '#DEA600'],
            ['nameLike' => 'Crystal Palace%',           'p' => '#1B458F', 's' => '#C4122E', 't' => '#FFFFFF'],
            ['nameLike' => 'Everton%',                  'p' => '#003399', 's' => '#FFFFFF', 't' => null],
            ['nameLike' => 'Fulham%',                   'p' => '#000000', 's' => '#FFFFFF', 't' => '#CC0000'],
            ['nameLike' => 'Leeds%',                    'p' => '#1D428A', 's' => '#FFFFFF', 't' => '#FFCD00'],
            ['nameLike' => 'Liverpool%',                'p' => '#C8102E', 's' => '#FFFFFF', 't' => '#00B2A9'],
            ['nameLike' => 'Manchester City%',          'p' => '#6CABDD', 's' => '#1C2C5B', 't' => '#FFFFFF'],
            ['nameLike' => 'Manchester United%',        'p' => '#DA291C', 's' => '#FBE122', 't' => '#FFFFFF'],
            ['nameLike' => 'Newcastle%',                'p' => '#241F20', 's' => '#FFFFFF', 't' => '#41B6E6'],
            ['nameLike' => 'Nottingham Forest%',        'p' => '#DD0000', 's' => '#FFFFFF', 't' => null],
            ['nameLike' => 'Tottenham%',                'p' => '#0A1C56', 's' => '#FFFFFF', 't' => null],
            ['nameLike' => 'West Ham%',                 'p' => '#7A263A', 's' => '#1BB1E7', 't' => '#F3D459'],
            ['nameLike' => 'Wolverhampton%',            'p' => '#FDB913', 's' => '#000000', 't' => '#FFFFFF'],
        ];

        foreach ($clubs as $c) {
            $updated = DB::table('clubs')
                ->where('name', 'ILIKE', $c['nameLike'])
                ->update([
                    'color_primary' => $c['p'],
                    'color_secondary' => $c['s'],
                    'color_tertiary' => $c['t'],
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                $this->command?->warn("No club match for pattern: {$c['nameLike']}");
            }
        }
    }
}
