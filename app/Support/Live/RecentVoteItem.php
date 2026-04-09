<?php

namespace App\Support\Live;

class RecentVoteItem
{
    public static function fromRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'leftPlayer' => $row->player_a_name,
            'rightPlayer' => $row->player_b_name,
            'leftPlayerId' => (int) $row->player_a_id,
            'rightPlayerId' => (int) $row->player_b_id,
            'winnerPlayerId' => (int) $row->winner_id,
            'attributeKey' => $row->attribute_key,
            'attributeLabel' => $row->attribute_label,
        ];
    }
}
