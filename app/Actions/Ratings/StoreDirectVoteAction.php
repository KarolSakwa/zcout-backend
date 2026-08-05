<?php

namespace App\Actions\Ratings;

use App\Models\Attribute;
use App\Models\User;
use App\Models\Vote;
use App\Support\VoteWeightResolver;
use Illuminate\Support\Facades\DB;

final class StoreDirectVoteAction
{
    public function __construct(
        private readonly ApplyVoteEventToRatingsAction $applyVoteEventToRatingsAction,
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

        return DB::transaction(function () use ($data, $userId, $attribute) {
            $user = User::query()->findOrFail($userId);

            /** @var VoteWeightResolver $voteWeightResolver */
            $voteWeightResolver = app(VoteWeightResolver::class);

            $weights = $voteWeightResolver->resolve(
                isAnonymous: false,
                influenceProfile: $user->getAttribute('influence_profile'),
            );

            $weightApplied = $weights->ratingWeight;
            $confidenceWeightApplied = $weights->confidenceWeight;
            $occurredAt = now();

            $ratingResult = $this->applyVoteEventToRatingsAction->executeDirect(
                playerId: (int) $data['player_id'],
                attributeId: (int) $attribute->id,
                value: (int) $data['value'],
                ratingWeight: $weightApplied,
                confidenceWeight: $confidenceWeightApplied,
                occurredAt: $occurredAt,
            );

            $vote = new Vote();
            $vote->source = 'direct';
            $vote->attribute_id = $attribute->id;
            $vote->duel_id = null;
            $vote->player_a_id = (int) $data['player_id'];
            $vote->player_b_id = null;
            $vote->winner_id = null;
            $vote->user_id = $userId;
            $vote->voter_hash = null;
            $vote->scout_report_submission_id = $data['scout_report_submission_id'] ?? null;
            $vote->weight_applied = $weightApplied;
            $vote->confidence_weight_applied = $confidenceWeightApplied;
            $vote->weight_version = 1;
            $vote->reputation_at_vote = null;
            $vote->risk_score_at_vote = null;
            $vote->value = (int) $data['value'];
            $vote->pre_rating_a = $ratingResult['pre_rating_a'];
            $vote->pre_rating_b = null;
            $vote->post_rating_a = $ratingResult['post_rating_a'];
            $vote->post_rating_b = null;
            $vote->created_at = $occurredAt;
            $vote->save();

            return [
                'ok' => true,
                'status' => 201,
                'body' => [
                    'vote_id' => $vote->id,
                    'attribute_id' => $attribute->id,
                    'player_id' => (int) $data['player_id'],
                    'value' => (int) $data['value'],
                    'pre_rating_a' => $ratingResult['pre_rating_a'],
                    'post_rating_a' => $ratingResult['post_rating_a'],
                    'delta_rating_a' => $ratingResult['delta_rating_a'],
                ],
            ];
        });
    }
}
