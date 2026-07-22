<?php

namespace App\Actions\Duels;

use App\Data\ActionFailure;
use App\Data\DuelVote\DuelVoteContext;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Support\Seed;

final class BuildDuelVoteContextAction
{
    public function execute(array $data): DuelVoteContext|ActionFailure
    {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (!$attribute) {
            return new ActionFailure(404, 'Attribute not found.');
        }

        $duel = Duel::query()->find((int) $data['duel_id']);

        if (!$duel) {
            return new ActionFailure(404, 'Duel not found.');
        }

        $duelPlayerAId = (int) $duel->player_a_id;
        $duelPlayerBId = (int) $duel->player_b_id;
        $winnerId = (int) $data['winner_id'];

        if ($winnerId !== $duelPlayerAId && $winnerId !== $duelPlayerBId) {
            return new ActionFailure(422, 'winner_id must be one of the duel players.');
        }

        $canonicalPlayerAId = min($duelPlayerAId, $duelPlayerBId);
        $canonicalPlayerBId = max($duelPlayerAId, $duelPlayerBId);

        $players = Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with([
                'positionRef:id,short_label',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
            ])
            ->whereIn('id', [$canonicalPlayerAId, $canonicalPlayerBId])
            ->get()
            ->keyBy('id');

        if (!isset($players[$canonicalPlayerAId]) || !isset($players[$canonicalPlayerBId])) {
            return new ActionFailure(404, 'Player not found.');
        }

        $positionA = strtoupper((string) ($players[$canonicalPlayerAId]->effective_position_short ?? ''));
        $positionB = strtoupper((string) ($players[$canonicalPlayerBId]->effective_position_short ?? ''));

        $beforeRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$canonicalPlayerAId, $canonicalPlayerBId])
            ->get()
            ->keyBy('player_id');

        $ratingBeforeA = (float) ($beforeRows[$canonicalPlayerAId]->rating ?? Seed::for($positionA, $attribute->key));
        $ratingBeforeB = (float) ($beforeRows[$canonicalPlayerBId]->rating ?? Seed::for($positionB, $attribute->key));

        $loserId = $winnerId === $duelPlayerAId ? $duelPlayerBId : $duelPlayerAId;

        return new DuelVoteContext(
            attribute: $attribute,
            duel: $duel,
            winnerId: $winnerId,
            loserId: $loserId,
            canonicalPlayerAId: $canonicalPlayerAId,
            canonicalPlayerBId: $canonicalPlayerBId,
            duelPlayerAId: $duelPlayerAId,
            duelPlayerBId: $duelPlayerBId,
            ratingBeforeA: $ratingBeforeA,
            ratingBeforeB: $ratingBeforeB,
        );
    }
}
