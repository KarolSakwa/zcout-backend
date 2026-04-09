<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Models\Vote;
use App\Services\RatingService;

final class StoreDirectVoteAction
{
    public function __construct(
        private readonly RatingService $ratingService,
    ) {
    }

    public function execute(array $data, int $userId): array
    {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (!$attribute) {
            return [
                'ok' => false,
                'status' => 404,
                'body' => ['message' => 'Attribute not found.'],
            ];
        }

        $alreadyExists = Vote::query()
            ->where('source', 'direct')
            ->where('user_id', $userId)
            ->where('player_a_id', (int) $data['player_id'])
            ->where('attribute_id', $attribute->id)
            ->exists();

        if ($alreadyExists) {
            return [
                'ok' => false,
                'status' => 409,
                'body' => [
                    'message' => 'Direct vote already exists for this player and attribute.',
                ],
            ];
        }

        $vote = new Vote();
        $vote->source = 'direct';
        $vote->attribute_id = $attribute->id;
        $vote->duel_id = null;
        $vote->player_a_id = (int) $data['player_id'];
        $vote->player_b_id = null;
        $vote->winner_id = null;
        $vote->user_id = $userId;
        $vote->voter_hash = null;
        $vote->weight_applied = 1.0;
        $vote->confidence_weight_applied = 1.0;
        $vote->weight_version = 1;
        $vote->reputation_at_vote = null;
        $vote->risk_score_at_vote = null;
        $vote->value = (int) $data['value'];
        $vote->created_at = now();
        $vote->save();

        $this->ratingService->applyDirectVote(
            playerId: (int) $data['player_id'],
            attributeId: (int) $attribute->id,
            value: (int) $data['value'],
            ratingWeight: (float) $vote->weight_applied,
            confidenceWeight: (float) $vote->confidence_weight_applied,
        );

        return [
            'ok' => true,
            'status' => 201,
            'body' => [
                'vote_id' => $vote->id,
                'attribute_id' => $attribute->id,
                'player_id' => (int) $data['player_id'],
                'value' => (int) $data['value'],
            ],
        ];
    }
}
