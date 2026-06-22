<?php

namespace App\Support\Homepage;

class NeedsMoreRatingsItem
{
    public static function fromRow(object $row): array
    {
        return [
            'id' => (string) $row->player_id,
            'playerId' => (int) $row->player_id,
            'player' => (string) $row->player_name,
            'slug' => $row->player_slug ? (string) $row->player_slug : null,
            'club' => $row->club_name ? (string) $row->club_name : null,
            'position' => $row->position_short ? (string) $row->position_short : null,
            'overall' => round((float) $row->overall, 2),
            'confidence' => round((float) $row->confidence, 2),
        ];
    }
}
