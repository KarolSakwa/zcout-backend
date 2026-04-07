<?php

namespace App\Support\Live;

class RecentVoteItem
{
    public static function fromRow(object $row): array
    {
        $winnerIsPlayerA = (int) $row->winner_id === (int) $row->player_a_id;

        return [
            'id' => (string) $row->id,
            'winner' => $row->winner_name,
            'loser' => $winnerIsPlayerA ? $row->player_b_name : $row->player_a_name,
            'winnerPlayerId' => (int) $row->winner_id,
            'loserPlayerId' => $winnerIsPlayerA ? (int) $row->player_b_id : (int) $row->player_a_id,
            'attributeKey' => $row->attribute_key,
            'attributeLabel' => $row->attribute_label,
        ];
    }
}
