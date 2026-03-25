<?php

return [
    'outfield' => [
        ['key' => 'pace', 'label' => 'Pace', 'group' => 'PACE'],
        ['key' => 'acceleration', 'label' => 'Acceleration', 'group' => 'PACE'],

        ['key' => 'ball_control', 'label' => 'Ball Control', 'group' => 'TECHNIQUE'],
        ['key' => 'dribbling', 'label' => 'Dribbling', 'group' => 'TECHNIQUE'],

        ['key' => 'finishing', 'label' => 'Finishing', 'group' => 'ATTACK'],
        ['key' => 'long_shots', 'label' => 'Long Shots', 'group' => 'ATTACK'],
        ['key' => 'attacking_movement', 'label' => 'Attacking Movement', 'group' => 'ATTACK'],
        ['key' => 'heading', 'label' => 'Heading', 'group' => 'ATTACK'],

        ['key' => 'passing', 'label' => 'Passing', 'group' => 'PASSING'],
        ['key' => 'crossing', 'label' => 'Crossing', 'group' => 'PASSING'],
        ['key' => 'creativity', 'label' => 'Creativity', 'group' => 'PASSING'],

        ['key' => 'leadership', 'label' => 'Leadership', 'group' => 'MENTALITY'],
        ['key' => 'concentration', 'label' => 'Concentration', 'group' => 'MENTALITY'],
        ['key' => 'aggression', 'label' => 'Aggression', 'group' => 'MENTALITY'],
        ['key' => 'composure', 'label' => 'Composure', 'group' => 'MENTALITY'],
        ['key' => 'work_rate', 'label' => 'Work Rate', 'group' => 'MENTALITY'],

        ['key' => 'strength', 'label' => 'Strength', 'group' => 'PHYSICALITY'],
        ['key' => 'agility', 'label' => 'Agility', 'group' => 'PHYSICALITY'],
        ['key' => 'stamina', 'label' => 'Stamina', 'group' => 'PHYSICALITY'],

        ['key' => 'marking', 'label' => 'Marking', 'group' => 'DEFENCE'],
        ['key' => 'tackling', 'label' => 'Tackling', 'group' => 'DEFENCE'],
        ['key' => 'interceptions', 'label' => 'Interceptions', 'group' => 'DEFENCE'],

        ['key' => 'penalties', 'label' => 'Penalties', 'group' => 'SET_PIECES'],
        ['key' => 'free_kicks', 'label' => 'Free Kicks', 'group' => 'SET_PIECES'],
    ],

    'outfield_axes' => [
        'DEFENCE' => ['marking', 'tackling', 'interceptions'],
        'MENTALITY' => ['leadership', 'concentration', 'aggression', 'composure', 'work_rate'],
        'AERIAL' => ['heading', 'strength', 'height_score'],
        'TECHNIQUE' => ['ball_control', 'dribbling'],
        'ATTACK' => ['finishing', 'long_shots', 'attacking_movement'],
        'PASSING' => ['passing', 'crossing', 'creativity'],
        'PACE' => ['pace', 'acceleration'],
        'PHYSICALITY' => ['strength', 'agility', 'stamina'],
    ],

    'gk' => [
        ['key' => 'pace', 'label' => 'Pace', 'group' => 'PACE'],
        ['key' => 'acceleration', 'label' => 'Acceleration', 'group' => 'PACE'],

        ['key' => 'agility', 'label' => 'Agility', 'group' => 'PHYSICALITY'],
        ['key' => 'strength', 'label' => 'Strength', 'group' => 'PHYSICALITY'],

        ['key' => 'leadership', 'label' => 'Leadership', 'group' => 'MENTALITY'],
        ['key' => 'concentration', 'label' => 'Concentration', 'group' => 'MENTALITY'],
        ['key' => 'composure', 'label' => 'Composure', 'group' => 'MENTALITY'],

        ['key' => 'gk_reflexes', 'label' => 'Reflexes', 'group' => 'SHOT_STOPPING'],
        ['key' => 'gk_one_on_ones', 'label' => '1v1', 'group' => 'SHOT_STOPPING'],
        ['key' => 'gk_handling', 'label' => 'Handling', 'group' => 'SHOT_STOPPING'],
        ['key' => 'gk_command_of_area', 'label' => 'Command of Area', 'group' => 'AERIAL'],

        ['key' => 'passing', 'label' => 'Passing', 'group' => 'DISTRIBUTION'],
        ['key' => 'gk_throwing', 'label' => 'Throwing', 'group' => 'DISTRIBUTION'],

        ['key' => 'gk_rushing_out', 'label' => 'Rushing Out', 'group' => 'RUSHING_OUT'],
        ['key' => 'gk_eccentricity', 'label' => 'Eccentricity', 'group' => 'ECCENTRICITY'],
    ],

    'gk_axes' => [
        'SHOT_STOPPING' => ['gk_reflexes', 'gk_one_on_ones', 'gk_handling'],
        'DISTRIBUTION' => ['passing', 'gk_throwing'],
        'AERIAL' => ['gk_command_of_area', 'strength', 'height_score'],
        'ECCENTRICITY' => ['gk_eccentricity'],
        'RUSHING_OUT' => ['gk_rushing_out'],
        'MENTALITY' => ['leadership', 'concentration', 'composure'],
        'PACE' => ['pace', 'acceleration'],
        'PHYSICALITY' => ['agility', 'strength'],
    ],
];
