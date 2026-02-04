<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Attribute;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $attributes = [
            // TECHNICAL
            ['key' => 'corners', 'label' => 'Corners', 'group' => 'technical', 'order' => 1],
            ['key' => 'crossing', 'label' => 'Crossing', 'group' => 'technical', 'order' => 2],
            ['key' => 'dribbling', 'label' => 'Dribbling', 'group' => 'technical', 'order' => 3],
            ['key' => 'finishing', 'label' => 'Finishing', 'group' => 'technical', 'order' => 4],
            ['key' => 'first_touch', 'label' => 'First Touch', 'group' => 'technical', 'order' => 5],
            ['key' => 'free_kick_taking', 'label' => 'Free Kick Taking', 'group' => 'technical', 'order' => 6],
            ['key' => 'heading', 'label' => 'Heading', 'group' => 'technical', 'order' => 7],
            ['key' => 'long_shots', 'label' => 'Long Shots', 'group' => 'technical', 'order' => 8],
            ['key' => 'long_throws', 'label' => 'Long Throws', 'group' => 'technical', 'order' => 9],
            ['key' => 'marking', 'label' => 'Marking', 'group' => 'technical', 'order' => 10],
            ['key' => 'passing', 'label' => 'Passing', 'group' => 'technical', 'order' => 11],
            ['key' => 'penalty_taking', 'label' => 'Penalty Taking', 'group' => 'technical', 'order' => 12],
            ['key' => 'tackling', 'label' => 'Tackling', 'group' => 'technical', 'order' => 13],
            ['key' => 'technique', 'label' => 'Technique', 'group' => 'technical', 'order' => 14],

            // MENTAL
            ['key' => 'aggression', 'label' => 'Aggression', 'group' => 'mental', 'order' => 1],
            ['key' => 'anticipation', 'label' => 'Anticipation', 'group' => 'mental', 'order' => 2],
            ['key' => 'bravery', 'label' => 'Bravery', 'group' => 'mental', 'order' => 3],
            ['key' => 'composure', 'label' => 'Composure', 'group' => 'mental', 'order' => 4],
            ['key' => 'concentration', 'label' => 'Concentration', 'group' => 'mental', 'order' => 5],
            ['key' => 'decisions', 'label' => 'Decisions', 'group' => 'mental', 'order' => 6],
            ['key' => 'determination', 'label' => 'Determination', 'group' => 'mental', 'order' => 7],
            ['key' => 'flair', 'label' => 'Flair', 'group' => 'mental', 'order' => 8],
            ['key' => 'leadership', 'label' => 'Leadership', 'group' => 'mental', 'order' => 9],
            ['key' => 'off_the_ball', 'label' => 'Off The Ball', 'group' => 'mental', 'order' => 10],
            ['key' => 'positioning', 'label' => 'Positioning', 'group' => 'mental', 'order' => 11],
            ['key' => 'teamwork', 'label' => 'Teamwork', 'group' => 'mental', 'order' => 12],
            ['key' => 'vision', 'label' => 'Vision', 'group' => 'mental', 'order' => 13],
            ['key' => 'work_rate', 'label' => 'Work Rate', 'group' => 'mental', 'order' => 14],

            // PHYSICAL
            ['key' => 'acceleration', 'label' => 'Acceleration', 'group' => 'physical', 'order' => 1],
            ['key' => 'agility', 'label' => 'Agility', 'group' => 'physical', 'order' => 2],
            ['key' => 'balance', 'label' => 'Balance', 'group' => 'physical', 'order' => 3],
            ['key' => 'jumping_reach', 'label' => 'Jumping Reach', 'group' => 'physical', 'order' => 4],
            ['key' => 'natural_fitness', 'label' => 'Natural Fitness', 'group' => 'physical', 'order' => 5],
            ['key' => 'pace', 'label' => 'Pace', 'group' => 'physical', 'order' => 6],
            ['key' => 'stamina', 'label' => 'Stamina', 'group' => 'physical', 'order' => 7],
            ['key' => 'strength', 'label' => 'Strength', 'group' => 'physical', 'order' => 8],
        ];

        foreach ($attributes as $attr) {
            Attribute::updateOrCreate(
                ['key' => $attr['key']],
                $attr
            );
        }
    }
}
