<?php

namespace App\Actions;

use App\Models\Duel;
use App\Models\Player;

final class MaterializeNextDuelAction
{
    public function handle(array $context): array
    {
        $attribute = $context['attribute'] ?? null;
        $pickedA = $context['picked_a'] ?? null;
        $pickedB = $context['picked_b'] ?? null;

        if (!$attribute) {
            return [
                'duel' => null,
                'players' => null,
                'status' => 'failed',
                'failure_reason' => 'missing_attribute',
            ];
        }

        if (!is_array($pickedA) || !is_array($pickedB)) {
            return [
                'duel' => null,
                'players' => null,
                'status' => 'failed',
                'failure_reason' => 'missing_picked_players',
            ];
        }

        $pickedAId = (int) ($pickedA['id'] ?? 0);
        $pickedBId = (int) ($pickedB['id'] ?? 0);

        if ($pickedAId <= 0 || $pickedBId <= 0 || $pickedAId === $pickedBId) {
            return [
                'duel' => null,
                'players' => null,
                'status' => 'failed',
                'failure_reason' => 'invalid_picked_players',
            ];
        }

        $playerAId = min($pickedAId, $pickedBId);
        $playerBId = max($pickedAId, $pickedBId);

        $players = Player::query()
            ->select([
                'id',
                'name',
                'slug',
                'number',
                'club_id',
                'country_id',
                'position_id',
                'fd_name',
                'fd_number',
                'manual_display_name',
                'manual_number',
                'fd_position_id',
                'manual_position_id',
            ])
            ->with([
                'clubRel:id,name,color_primary,color_secondary,color_tertiary',
                'countryRef:id,name,iso2',
                'positionRef:id,short_label,label,key',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
            ])
            ->whereIn('id', [$playerAId, $playerBId])
            ->get()
            ->keyBy('id');

        if ($players->count() < 2) {
            return [
                'duel' => null,
                'players' => null,
                'status' => 'failed',
                'failure_reason' => 'players_not_found',
            ];
        }

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ]);

        return [
            'duel' => $duel,
            'players' => $players,
            'status' => 'ok',
            'failure_reason' => null,
        ];
    }
}
