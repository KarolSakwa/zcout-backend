<?php

return [
    'outfield' => [
        ['key' => 'pace', 'label' => 'Pace', 'group' => 'PACE'],
        ['key' => 'acceleration', 'label' => 'Acceleration', 'group' => 'PACE'],

        ['key' => 'ball_control', 'label' => 'Ball Control', 'group' => 'TECHNIQUE'],
        ['key' => 'dribbling', 'label' => 'Dribbling', 'group' => 'TECHNIQUE'],

        ['key' => 'finishing', 'label' => 'Finishing', 'group' => 'OFFENSIVE'],
        ['key' => 'long_shots', 'label' => 'Long Shots', 'group' => 'OFFENSIVE'],
        ['key' => 'attacking_movement', 'label' => 'Attacking Movement', 'group' => 'OFFENSIVE'],
        ['key' => 'heading', 'label' => 'Heading', 'group' => 'OFFENSIVE'],

        ['key' => 'passing', 'label' => 'Passing', 'group' => 'PASSING'],
        ['key' => 'crossing', 'label' => 'Crossing', 'group' => 'PASSING'],
        ['key' => 'creativity', 'label' => 'Creativity', 'group' => 'PASSING'],

        ['key' => 'leadership', 'label' => 'Leadership', 'group' => 'MENTAL'],
        ['key' => 'concentration', 'label' => 'Concentration', 'group' => 'MENTAL'],
        ['key' => 'aggression', 'label' => 'Aggression', 'group' => 'MENTAL'],
        ['key' => 'composure', 'label' => 'Composure', 'group' => 'MENTAL'],
        ['key' => 'work_rate', 'label' => 'Work Rate', 'group' => 'MENTAL'],

        ['key' => 'strength', 'label' => 'Strength', 'group' => 'PHYSICAL'],
        ['key' => 'agility', 'label' => 'Agility', 'group' => 'PHYSICAL'],
        ['key' => 'stamina', 'label' => 'Stamina', 'group' => 'PHYSICAL'],

        ['key' => 'marking', 'label' => 'Marking', 'group' => 'DEFENSIVE'],
        ['key' => 'tackling', 'label' => 'Tackling', 'group' => 'DEFENSIVE'],
        ['key' => 'interceptions', 'label' => 'Interceptions', 'group' => 'DEFENSIVE'],

        ['key' => 'penalties', 'label' => 'Penalties', 'group' => 'SET_PIECES'],
        ['key' => 'free_kicks', 'label' => 'Free Kicks', 'group' => 'SET_PIECES'],
    ],

    'outfield_axes' => [
        'PACE' => ['pace', 'acceleration'],
        'TECHNIQUE' => ['ball_control', 'dribbling'],
        'OFFENSIVE' => ['finishing', 'long_shots', 'attacking_movement'],
        'PASSING' => ['passing', 'crossing', 'creativity'],
        'MENTAL' => ['leadership', 'concentration', 'aggression', 'composure', 'work_rate'],
        'PHYSICAL' => ['strength', 'agility', 'stamina'],
        'DEFENSIVE' => ['marking', 'tackling', 'interceptions'],
        'AERIAL' => ['heading', 'strength', 'height_score'],
    ],

    'gk' => [
        ['key' => 'pace', 'label' => 'Pace', 'group' => 'PACE'],
        ['key' => 'acceleration', 'label' => 'Acceleration', 'group' => 'PACE'],

        ['key' => 'agility', 'label' => 'Agility', 'group' => 'PHYSICAL'],
        ['key' => 'strength', 'label' => 'Strength', 'group' => 'PHYSICAL'],

        ['key' => 'leadership', 'label' => 'Leadership', 'group' => 'MENTAL'],
        ['key' => 'concentration', 'label' => 'Concentration', 'group' => 'MENTAL'],
        ['key' => 'composure', 'label' => 'Composure', 'group' => 'MENTAL'],

        ['key' => 'gk_reflexes', 'label' => 'Reflexes', 'group' => 'GOALKEEPING'],
        ['key' => 'gk_one_on_ones', 'label' => '1v1', 'group' => 'GOALKEEPING'],
        ['key' => 'gk_handling', 'label' => 'Handling', 'group' => 'GOALKEEPING'],
        ['key' => 'gk_command_of_area', 'label' => 'Command of Area', 'group' => 'GOALKEEPING'],

        ['key' => 'gk_passing', 'label' => 'Passing', 'group' => 'DISTRIBUTION'],
        ['key' => 'gk_throwing', 'label' => 'Throwing', 'group' => 'DISTRIBUTION'],

        ['key' => 'gk_rushing_out', 'label' => 'Rushing Out', 'group' => 'SWEEPER'],
        ['key' => 'gk_eccentricity', 'label' => 'Eccentricity', 'group' => 'SWEEPER'],
    ],

    'gk_axes' => [
        'PACE' => ['pace', 'acceleration'],
        'REFLEXES' => ['gk_reflexes', 'agility'],
        'ONE_ON_ONES' => ['gk_one_on_ones'],
        'HANDLING' => ['gk_handling'],
        'AERIAL' => ['gk_command_of_area', 'strength', 'height_score'],
        'DISTRIBUTION' => ['gk_passing', 'gk_throwing'],
        'RUSHING_OUT' => ['gk_rushing_out'],
        'MENTAL' => ['leadership', 'concentration', 'composure'],
    ],

    'computed' => [
        'height_score' => [
            'source' => 'players.height_cm',
        ],
    ],
];
