<?php

$default = 65.0;

$attrs = [
    'acceleration','aggression','agility','anticipation','balance','bravery','composure','concentration',
    'corners','crossing','decisions','determination','dribbling','finishing','first_touch','flair',
    'free_kick_taking','heading','jumping_reach','leadership','long_shots','long_throws','marking',
    'natural_fitness','off_the_ball','pace','passing','penalty_taking','positioning','stamina','strength',
    'tackling','teamwork','technique','vision','work_rate',
];

$base = array_fill_keys($attrs, $default);

$GK = array_merge($base, [
    'pace' => 45, 'acceleration' => 48, 'agility' => 50, 'stamina' => 45, 'strength' => 55,
    'dribbling' => 30, 'first_touch' => 38, 'passing' => 40, 'vision' => 38, 'crossing' => 30,
    'finishing' => 25, 'long_shots' => 30, 'heading' => 30,
    'tackling' => 30, 'marking' => 35, 'positioning' => 55,
    'aggression' => 45, 'bravery' => 55, 'composure' => 65, 'concentration' => 70,
    'decisions' => 66, 'determination' => 60, 'leadership' => 60, 'off_the_ball' => 35,
    'teamwork' => 62, 'work_rate' => 60,

    'anticipation' => 60,
    'balance' => 48,
    'corners' => 20,
    'flair' => 20,
    'free_kick_taking' => 20,
    'jumping_reach' => 35,
    'long_throws' => 60,
    'natural_fitness' => 47,
    'penalty_taking' => 20,
    'technique' => 36,
]);

$CB = array_merge($base, [
    'pace' => 60, 'acceleration' => 60, 'agility' => 58, 'stamina' => 62, 'strength' => 78,
    'dribbling' => 45, 'first_touch' => 55, 'passing' => 58, 'vision' => 54, 'crossing' => 40,
    'finishing' => 40, 'long_shots' => 45, 'heading' => 78,
    'tackling' => 76, 'marking' => 78, 'positioning' => 78,
    'aggression' => 70, 'bravery' => 75, 'composure' => 60, 'concentration' => 68,
    'decisions' => 60, 'determination' => 65, 'leadership' => 62, 'off_the_ball' => 50,
    'teamwork' => 62, 'work_rate' => 65,

    'anticipation' => 64,
    'balance' => 56,
    'corners' => 35,
    'flair' => 35,
    'free_kick_taking' => 35,
    'jumping_reach' => 80,
    'long_throws' => 55,
    'natural_fitness' => 64,
    'penalty_taking' => 35,
    'technique' => 53,
]);

$RB = array_merge($base, [
    'pace' => 75, 'acceleration' => 77, 'agility' => 72, 'stamina' => 78, 'strength' => 65,
    'dribbling' => 62, 'first_touch' => 62, 'passing' => 64, 'vision' => 60, 'crossing' => 68,
    'finishing' => 45, 'long_shots' => 52, 'heading' => 55,
    'tackling' => 70, 'marking' => 68, 'positioning' => 68,
    'aggression' => 60, 'bravery' => 60, 'composure' => 62, 'concentration' => 64,
    'decisions' => 62, 'determination' => 64, 'leadership' => 55, 'off_the_ball' => 62,
    'teamwork' => 64, 'work_rate' => 72,

    'anticipation' => 62,
    'balance' => 70,
    'corners' => 65,
    'flair' => 55,
    'free_kick_taking' => 45,
    'jumping_reach' => 58,
    'long_throws' => 70,
    'natural_fitness' => 80,
    'penalty_taking' => 45,
    'technique' => 63,
]);

$LB = $RB;

$DM = array_merge($base, [
    'pace' => 66, 'acceleration' => 68, 'agility' => 66, 'stamina' => 76, 'strength' => 70,
    'dribbling' => 60, 'first_touch' => 64, 'passing' => 68, 'vision' => 66, 'crossing' => 45,
    'finishing' => 45, 'long_shots' => 56, 'heading' => 62,
    'tackling' => 72, 'marking' => 70, 'positioning' => 72,
    'aggression' => 60, 'bravery' => 65, 'composure' => 64, 'concentration' => 68,
    'decisions' => 66, 'determination' => 66, 'leadership' => 58, 'off_the_ball' => 58,
    'teamwork' => 66, 'work_rate' => 70,

    'anticipation' => 66,
    'balance' => 64,
    'corners' => 40,
    'flair' => 50,
    'free_kick_taking' => 50,
    'jumping_reach' => 65,
    'long_throws' => 55,
    'natural_fitness' => 78,
    'penalty_taking' => 50,
    'technique' => 64,
]);

$CM = array_merge($base, [
    'pace' => 68, 'acceleration' => 70, 'agility' => 70, 'stamina' => 78, 'strength' => 68,
    'dribbling' => 68, 'first_touch' => 70, 'passing' => 72, 'vision' => 72, 'crossing' => 48,
    'finishing' => 58, 'long_shots' => 62, 'heading' => 58,
    'tackling' => 64, 'marking' => 62, 'positioning' => 66,
    'aggression' => 55, 'bravery' => 58, 'composure' => 66, 'concentration' => 64,
    'decisions' => 68, 'determination' => 64, 'leadership' => 58, 'off_the_ball' => 62,
    'teamwork' => 68, 'work_rate' => 66,

    'anticipation' => 66,
    'balance' => 68,
    'corners' => 50,
    'flair' => 60,
    'free_kick_taking' => 60,
    'jumping_reach' => 60,
    'long_throws' => 50,
    'natural_fitness' => 80,
    'penalty_taking' => 55,
    'technique' => 70,
]);

$RM = array_merge($base, [
    'pace' => 76, 'acceleration' => 78, 'agility' => 76, 'stamina' => 78, 'strength' => 62,
    'dribbling' => 72, 'first_touch' => 70, 'passing' => 68, 'vision' => 66, 'crossing' => 72,
    'finishing' => 60, 'long_shots' => 60, 'heading' => 55,
    'tackling' => 56, 'marking' => 54, 'positioning' => 56,
    'aggression' => 55, 'bravery' => 55, 'composure' => 62, 'concentration' => 60,
    'decisions' => 60, 'determination' => 62, 'leadership' => 52, 'off_the_ball' => 70,
    'teamwork' => 64, 'work_rate' => 66,

    'anticipation' => 60,
    'balance' => 74,
    'corners' => 70,
    'flair' => 68,
    'free_kick_taking' => 65,
    'jumping_reach' => 55,
    'long_throws' => 55,
    'natural_fitness' => 80,
    'penalty_taking' => 60,
    'technique' => 70,
]);

$LM = $RM;

$RW = array_merge($base, [
    'pace' => 85, 'acceleration' => 86, 'agility' => 84, 'stamina' => 76, 'strength' => 60,
    'dribbling' => 82, 'first_touch' => 78, 'passing' => 72, 'vision' => 72, 'crossing' => 75,
    'finishing' => 68, 'long_shots' => 70, 'heading' => 55,
    'tackling' => 50, 'marking' => 48, 'positioning' => 52,
    'aggression' => 55, 'bravery' => 55, 'composure' => 64, 'concentration' => 58,
    'decisions' => 60, 'determination' => 60, 'leadership' => 50, 'off_the_ball' => 78,
    'teamwork' => 62, 'work_rate' => 64,

    'anticipation' => 62,
    'balance' => 82,
    'corners' => 72,
    'flair' => 78,
    'free_kick_taking' => 70,
    'jumping_reach' => 55,
    'long_throws' => 45,
    'natural_fitness' => 78,
    'penalty_taking' => 62,
    'technique' => 77,
]);

$LW = $RW;

$AM = array_merge($base, [
    'pace' => 74, 'acceleration' => 76, 'agility' => 78, 'stamina' => 74, 'strength' => 62,
    'dribbling' => 78, 'first_touch' => 78, 'passing' => 78, 'vision' => 80, 'crossing' => 60,
    'finishing' => 68, 'long_shots' => 70, 'heading' => 55,
    'tackling' => 55, 'marking' => 52, 'positioning' => 56,
    'aggression' => 55, 'bravery' => 55, 'composure' => 66, 'concentration' => 62,
    'decisions' => 68, 'determination' => 62, 'leadership' => 54, 'off_the_ball' => 76,
    'teamwork' => 64, 'work_rate' => 64,

    'anticipation' => 68,
    'balance' => 76,
    'corners' => 60,
    'flair' => 80,
    'free_kick_taking' => 72,
    'jumping_reach' => 56,
    'long_throws' => 40,
    'natural_fitness' => 76,
    'penalty_taking' => 68,
    'technique' => 78,
]);

$ST = array_merge($base, [
    'pace' => 78, 'acceleration' => 80, 'agility' => 75, 'stamina' => 76, 'strength' => 78,
    'dribbling' => 70, 'first_touch' => 70, 'passing' => 60, 'vision' => 60, 'crossing' => 45,
    'finishing' => 84, 'long_shots' => 72, 'heading' => 78,
    'tackling' => 48, 'marking' => 46, 'positioning' => 54,
    'aggression' => 60, 'bravery' => 60, 'composure' => 66, 'concentration' => 56,
    'decisions' => 58, 'determination' => 62, 'leadership' => 52, 'off_the_ball' => 80,
    'teamwork' => 60, 'work_rate' => 64,

    'anticipation' => 64,
    'balance' => 73,
    'corners' => 40,
    'flair' => 65,
    'free_kick_taking' => 62,
    'jumping_reach' => 80,
    'long_throws' => 35,
    'natural_fitness' => 78,
    'penalty_taking' => 78,
    'technique' => 67,
]);

$avg = function (array ...$roles) use ($attrs): array {
    $out = [];
    foreach ($attrs as $k) {
        $sum = 0.0;
        $n = 0;
        foreach ($roles as $r) {
            if (array_key_exists($k, $r)) { $sum += (float) $r[$k]; $n++; }
        }
        $out[$k] = $n ? round($sum / $n, 2) : 65.0;
    }
    return $out;
};

$DEF = $avg($CB, $LB, $RB);
$MID = $avg($DM, $CM, $AM, $LM, $RM);
$ATT = $avg($LW, $RW, $ST);

return [
    'default_seed' => $default,
    'matrix' => [
        'GK' => $GK,
        'CB' => $CB,
        'LB' => $LB,
        'RB' => $RB,
        'DEF' => $DEF,

        'DM' => $DM,
        'CM' => $CM,
        'AM' => $AM,
        'LM' => $LM,
        'RM' => $RM,
        'MID' => $MID,

        'LW' => $LW,
        'RW' => $RW,
        'ST' => $ST,
        'ATT' => $ATT,
    ],
];
