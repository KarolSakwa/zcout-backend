<?php

namespace App\Actions\Votes;

use App\Actions\ApplyVoteEventToRatingsAction;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use App\Models\VoteWeightLog;
use App\Services\RatingService;
use App\Support\Seed;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SubmitDuelVote
{
    private const WEIGHT_VERSION = 1;
    private const RATING_ALGORITHM_VERSION = 1;
    private const BASE_DUEL_WEIGHT = 1.0;
    private const AUTH_FACTOR_ANON = 0.5;
    private const AUTH_FACTOR_AUTHED = 1.0;
    private const TRUST_RATING_FACTOR_DEFAULT = 1.0;
    private const TRUST_CONFIDENCE_FACTOR_ANON = 0.2;
    private const TRUST_CONFIDENCE_FACTOR_AUTHED = 1.0;
    private const INTEGRITY_FACTOR_DEFAULT = 1.0;
    private const BIAS_FACTOR_DEFAULT = 1.0;
    private const ACTIVITY_FACTOR_DEFAULT = 1.0;
    private const ROLE_FACTOR_DEFAULT = 1.0;

    public function __construct(
        private readonly ApplyVoteEventToRatingsAction $applyVoteEventToRatingsAction = new ApplyVoteEventToRatingsAction(new RatingService()),
    ) {
    }

    public function handle(array $data, ?int $currentUserId, string $voterHash): array
    {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (! $attribute) {
            throw new RuntimeException('Attribute not found.');
        }

        $reqA = (int) $data['player_a_id'];
        $reqB = (int) $data['player_b_id'];
        $winnerId = (int) $data['winner_id'];

        if ($winnerId !== $reqA && $winnerId !== $reqB) {
            throw new RuntimeException('winner_id must be one of the duel players.');
        }

        $playerA = min($reqA, $reqB);
        $playerB = max($reqA, $reqB);

        $players = Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with(['positionRef:id,short_label', 'fdPositionRef:id,short_label,key,label', 'manualPositionRef:id,short_label,key,label'])
            ->whereIn('id', [$playerA, $playerB])
            ->get()
            ->keyBy('id');

        if (! isset($players[$playerA]) || ! isset($players[$playerB])) {
            throw new RuntimeException('Player not found.');
        }

        $posA = strtoupper((string) ($players[$playerA]->effective_position_short ?? ''));
        $posB = strtoupper((string) ($players[$playerB]->effective_position_short ?? ''));

        $beforeRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $beforeA = (float) ($beforeRows[$playerA]->rating ?? Seed::for($posA, $attribute->key));
        $beforeB = (float) ($beforeRows[$playerB]->rating ?? Seed::for($posB, $attribute->key));

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id' => $playerA,
            'player_b_id' => $playerB,
        ]);

        $loserId = $winnerId === $reqA ? $reqB : $reqA;
        $isAuthed = $currentUserId !== null;

        $weightVersion = self::WEIGHT_VERSION;
        $ratingAlgorithmVersion = self::RATING_ALGORITHM_VERSION;
        $baseDuelWeight = self::BASE_DUEL_WEIGHT;
        $authFactor = $isAuthed ? self::AUTH_FACTOR_AUTHED : self::AUTH_FACTOR_ANON;
        $trustRatingFactor = self::TRUST_RATING_FACTOR_DEFAULT;
        $trustConfidenceFactor = $isAuthed ? self::TRUST_CONFIDENCE_FACTOR_AUTHED : self::TRUST_CONFIDENCE_FACTOR_ANON;
        $integrityFactor = self::INTEGRITY_FACTOR_DEFAULT;
        $biasFactor = self::BIAS_FACTOR_DEFAULT;
        $activityFactor = self::ACTIVITY_FACTOR_DEFAULT;
        $roleFactor = self::ROLE_FACTOR_DEFAULT;

        $ratingWeight = $baseDuelWeight
            * $authFactor
            * $trustRatingFactor
            * $integrityFactor
            * $biasFactor
            * $activityFactor
            * $roleFactor;

        $confidenceWeight = $baseDuelWeight
            * $authFactor
            * $trustConfidenceFactor
            * $integrityFactor
            * $biasFactor
            * $activityFactor
            * $roleFactor;

        $occurredAt = now();

        $vote = null;
        $afterA = $beforeA;
        $afterB = $beforeB;

        DB::transaction(function () use (
            $attribute,
            $duel,
            $playerA,
            $playerB,
            $winnerId,
            $loserId,
            $currentUserId,
            $voterHash,
            $beforeA,
            $beforeB,
            $weightVersion,
            $ratingAlgorithmVersion,
            $baseDuelWeight,
            $authFactor,
            $trustRatingFactor,
            $trustConfidenceFactor,
            $integrityFactor,
            $biasFactor,
            $activityFactor,
            $roleFactor,
            $ratingWeight,
            $confidenceWeight,
            $occurredAt,
            &$vote,
            &$afterA,
            &$afterB
        ): void {
            $vote = new Vote();
            $vote->source = 'duel';
            $vote->attribute_id = $attribute->id;
            $vote->duel_id = $duel->id;
            $vote->player_a_id = $playerA;
            $vote->player_b_id = $playerB;
            $vote->winner_id = $winnerId;
            $vote->user_id = $currentUserId;
            $vote->voter_hash = $voterHash;
            $vote->weight_applied = $ratingWeight;
            $vote->confidence_weight_applied = $confidenceWeight;
            $vote->weight_version = $weightVersion;
            $vote->reputation_at_vote = null;
            $vote->risk_score_at_vote = null;
            $vote->value = null;
            $vote->pre_rating_a = number_format($beforeA, 3, '.', '');
            $vote->pre_rating_b = number_format($beforeB, 3, '.', '');
            $vote->created_at = $occurredAt;
            $vote->save();

            VoteWeightLog::query()->create([
                'vote_id' => $vote->id,
                'weight_version' => $weightVersion,
                'rating_algorithm_version' => $ratingAlgorithmVersion,
                'base_duel_weight' => $baseDuelWeight,
                'auth_factor' => $authFactor,
                'trust_rating_factor' => $trustRatingFactor,
                'trust_confidence_factor' => $trustConfidenceFactor,
                'integrity_factor' => $integrityFactor,
                'bias_factor' => $biasFactor,
                'activity_factor' => $activityFactor,
                'role_factor' => $roleFactor,
                'rating_weight_applied' => $ratingWeight,
                'confidence_weight_applied' => $confidenceWeight,
            ]);

            $this->applyVoteEventToRatingsAction->executeDuel(
                attributeId: $attribute->id,
                winnerId: $winnerId,
                loserId: $loserId,
                ratingWeight: $ratingWeight,
                confidenceWeight: $confidenceWeight,
                occurredAt: $occurredAt,
            );

            $afterRows = PlayerAttributeRating::query()
                ->where('attribute_id', $attribute->id)
                ->whereIn('player_id', [$playerA, $playerB])
                ->get()
                ->keyBy('player_id');

            $afterA = (float) ($afterRows[$playerA]->rating ?? $beforeA);
            $afterB = (float) ($afterRows[$playerB]->rating ?? $beforeB);

            $vote->post_rating_a = number_format($afterA, 3, '.', '');
            $vote->post_rating_b = number_format($afterB, 3, '.', '');
            $vote->save();
        });

        return [
            'attribute_id' => $attribute->id,
            'attribute_key' => $attribute->key,
            'duel_id' => $duel->id,
            'vote_id' => $vote?->id,
            'player_a_id' => $playerA,
            'player_b_id' => $playerB,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'before_a' => $beforeA,
            'before_b' => $beforeB,
            'after_a' => $afterA,
            'after_b' => $afterB,
            'rating_weight' => $ratingWeight,
            'confidence_weight' => $confidenceWeight,
        ];
    }
}
