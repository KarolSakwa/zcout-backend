<?php

return [
    'long_tail_share' => 0.01,

    'intent_mix' => [
        'calibration' => 0.10,
        'production' => 0.90,
    ],

    'production_tier_mix' => [
        'A' => 0.75,
        'B' => 0.20,
        'C' => 0.05,
    ],

    'production_position_profile_mix' => [
        'exact' => 0.35,
        'adjacent' => 0.45,
        'same_side' => 0.15,
        'any' => 0.05,
    ],

    'production_gap_profile_mix' => [
        'close' => 0.75,
        'medium' => 0.25,
    ],

    'rating_gap' => [
        'close_max' => 6,
        'medium_min' => 7,
        'medium_max' => 16,
        'obvious_min' => 25,
    ],

    'weights' => [
        'rep_pow' => 1.0,
        'need_pow' => 1.2,
    ],

    'positional_adjacent' => [
        'GK' => ['GK'],

        'CB' => ['CB','LB','RB','DM'],
        'LB' => ['LB','LWB','CB','LM'],
        'RB' => ['RB','RWB','CB','RM'],
        'LWB' => ['LWB','LB','LM','LW'],
        'RWB' => ['RWB','RB','RM','RW'],
        'WB' => ['WB','LB','RB'],
        'DEF' => ['DEF','CB','LB','RB','LWB','RWB','WB','DM'],

        'MID' => ['MID','DM','CM','AM','LM','RM','LW','RW'],
        'DM' => ['DM','CM','CB'],
        'CM' => ['CM','DM','AM'],
        'AM' => ['AM','CM','LW','RW','ST'],

        'LW' => ['LW','AM','ST','LM'],
        'RW' => ['RW','AM','ST','RM'],
        'LM' => ['LM','LW','CM','LB'],
        'RM' => ['RM','RW','CM','RB'],

        'ST' => ['ST','AM','LW','RW','CF'],
        'CF' => ['CF','ST','AM'],
        'ATT' => ['ST','CF','LW','RW','AM','ATT'],
    ],

    'positional_sides' => [
        'def' => ['CB','LB','RB','LWB','RWB','WB','DM','DEF'],
        'off' => ['CM','AM','LM','RM','LW','RW','ST','CF','ATT','MID'],
    ],
];
