<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // GK
            ['key'=>'GK',  'label'=>'Goalkeeper',          'short_label'=>'GK',  'group'=>'GK',  'order'=>10],

            // DEF
            ['key'=>'RB',  'label'=>'Right-Back',          'short_label'=>'RB',  'group'=>'DEF', 'order'=>20],
            ['key'=>'LB',  'label'=>'Left-Back',           'short_label'=>'LB',  'group'=>'DEF', 'order'=>21],
            ['key'=>'CB',  'label'=>'Centre-Back',         'short_label'=>'CB',  'group'=>'DEF', 'order'=>22],
            ['key'=>'DEF', 'label'=>'Defence',             'short_label'=>'DEF', 'group'=>'DEF', 'order'=>29],

            // MID
            ['key'=>'DM',  'label'=>'Defensive Midfield',  'short_label'=>'DM',  'group'=>'MID', 'order'=>30],
            ['key'=>'CM',  'label'=>'Central Midfield',    'short_label'=>'CM',  'group'=>'MID', 'order'=>31],
            ['key'=>'AM',  'label'=>'Attacking Midfield',  'short_label'=>'AM',  'group'=>'MID', 'order'=>32],
            ['key'=>'MID', 'label'=>'Midfield',            'short_label'=>'MID', 'group'=>'MID', 'order'=>39],
            ['key'=>'RM', 'label'=>'Right Midfield', 'short_label'=>'RM', 'group'=>'MID', 'order'=>33],
            ['key'=>'LM', 'label'=>'Left Midfield',  'short_label'=>'LM', 'group'=>'MID', 'order'=>34],

            // ATT
            ['key'=>'RW',  'label'=>'Right Winger',         'short_label'=>'RW',  'group'=>'ATT', 'order'=>40],
            ['key'=>'LW',  'label'=>'Left Winger',          'short_label'=>'LW',  'group'=>'ATT', 'order'=>41],
            ['key'=>'ST',  'label'=>'Centre-Forward',       'short_label'=>'ST',  'group'=>'ATT', 'order'=>42],
            ['key'=>'ATT', 'label'=>'Offence',              'short_label'=>'ATT', 'group'=>'ATT', 'order'=>49],
        ];

        foreach ($rows as $r) {
            DB::table('positions')->updateOrInsert(
                ['key' => $r['key']],
                [
                    'label' => $r['label'],
                    'short_label' => $r['short_label'],
                    'group' => $r['group'],
                    'order' => $r['order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
