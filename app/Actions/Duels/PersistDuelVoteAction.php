<?php

namespace App\Actions\Duels;

use App\Actions\Ratings\ApplyVoteEventToRatingsAction;
use App\Data\DuelVote\DuelVoteContext;
use App\Data\DuelVote\PersistedDuelVoteResult;
use App\Data\DuelVote\VoterIdentity;
use App\Models\Vote;
use App\Models\VoteWeightLog;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class PersistDuelVoteAction
{
    private const WEIGHT_VERSION = 1;
    private const RATING_ALGORITHM_VERSION = 1;

    public function __construct(
        private readonly ApplyVoteEventToRatingsAction $applyVoteEventToRatingsAction,
    ) {
    }

    public function execute(
        DuelVoteContext $context,
        VoterIdentity $identity,
        float $ratingWeight,
        float $confidenceWeight,
        DateTimeInterface $occurredAt,
    ): PersistedDuelVoteResult {
        return DB::transaction(function () use (
            $context,
            $identity,
            $ratingWeight,
            $confidenceWeight,
            $occurredAt,
        ) {
            $vote = new Vote();
            $vote->source = 'duel';
            $vote->attribute_id = $context->attribute->id;
            $vote->duel_id = $context->duel->id;
            $vote->player_a_id = $context->canonicalPlayerAId;
            $vote->player_b_id = $context->canonicalPlayerBId;
            $vote->winner_id = $context->winnerId;
            $vote->user_id = $identity->userId;
            $vote->voter_hash = $identity->voterHash;
            $vote->weight_applied = $ratingWeight;
            $vote->confidence_weight_applied = $confidenceWeight;
            $vote->weight_version = self::WEIGHT_VERSION;
            $vote->reputation_at_vote = null;
            $vote->risk_score_at_vote = null;
            $vote->value = null;
            $vote->created_at = $occurredAt;
            $vote->save();

            VoteWeightLog::create([
                'vote_id' => $vote->id,
                'weight_version' => self::WEIGHT_VERSION,
                'rating_algorithm_version' => self::RATING_ALGORITHM_VERSION,
                'base_duel_weight' => 1.0,
                'rating_weight_applied' => $ratingWeight,
                'confidence_weight_applied' => $confidenceWeight,
            ]);

            $applyResult = $this->applyVoteEventToRatingsAction->executeDuel(
                attributeId: $context->attribute->id,
                winnerId: $context->winnerId,
                loserId: $context->loserId,
                ratingWeight: $ratingWeight,
                confidenceWeight: $confidenceWeight,
                occurredAt: $occurredAt,
            );

            if ($context->winnerId === $context->canonicalPlayerAId) {
                $ratingBeforeA = (float) $applyResult['winner']['pre_rating'];
                $ratingBeforeB = (float) $applyResult['loser']['pre_rating'];
                $ratingAfterA = (float) $applyResult['winner']['post_rating'];
                $ratingAfterB = (float) $applyResult['loser']['post_rating'];
            } else {
                $ratingBeforeA = (float) $applyResult['loser']['pre_rating'];
                $ratingBeforeB = (float) $applyResult['winner']['pre_rating'];
                $ratingAfterA = (float) $applyResult['loser']['post_rating'];
                $ratingAfterB = (float) $applyResult['winner']['post_rating'];
            }

            $vote->pre_rating_a = number_format($ratingBeforeA, 3, '.', '');
            $vote->pre_rating_b = number_format($ratingBeforeB, 3, '.', '');
            $vote->post_rating_a = number_format($ratingAfterA, 3, '.', '');
            $vote->post_rating_b = number_format($ratingAfterB, 3, '.', '');
            $vote->save();

            return new PersistedDuelVoteResult(
                vote: $vote,
                ratingAfterA: $ratingAfterA,
                ratingAfterB: $ratingAfterB,
            );
        });
    }
}
