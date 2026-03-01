<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Attribute;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $outfield = config('zcout_attributes.outfield', []);
        $gk = config('zcout_attributes.gk', []);

        $outfieldKeys = [];
        foreach ($outfield as $row) {
            $k = (string) ($row['key'] ?? '');
            if ($k !== '') {
                $outfieldKeys[$k] = true;
            }
        }

        $gkOnlyKeys = [];
        foreach ($gk as $row) {
            $k = (string) ($row['key'] ?? '');
            if ($k !== '' && !isset($outfieldKeys[$k])) {
                $gkOnlyKeys[$k] = true;
            }
        }

        $all = [];
        $seen = [];

        foreach ([$outfield, $gk] as $list) {
            foreach ($list as $row) {
                $key = (string) ($row['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $label = (string) ($row['label'] ?? $key);
                $group = (string) ($row['group'] ?? 'OTHER');
                $scope = isset($gkOnlyKeys[$key]) ? 'gk' : 'both';

                $all[] = [
                    'key' => $key,
                    'label' => $label,
                    'group' => $group,
                    'scope' => $scope,
                ];
            }
        }

        $groupCounters = [];
        foreach ($all as $attr) {
            $group = $attr['group'];
            $groupCounters[$group] = ($groupCounters[$group] ?? 0) + 1;

            Attribute::updateOrCreate(
                ['key' => $attr['key']],
                [
                    'key' => $attr['key'],
                    'label' => $attr['label'],
                    'group' => $attr['group'],
                    'order' => $groupCounters[$group],
                    'scope' => $attr['scope'],
                ]
            );
        }
    }
}
