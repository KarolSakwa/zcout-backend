<?php

namespace App\Actions\Ratings;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Services\RatingService;
use App\Support\Seed;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use App\Events\PlayerAttributeRatingUpdated;

final class ApplyVoteEventToRatingsAction
{
    public function __construct(
        private readonly RatingService $ratingService,
        private readonly RecalculatePlayerOverallAction $recalculatePlayerOverallAction,
    ) {
    }

    public function executeDuel(
        int $attributeId,
        int $winnerId,
        int $loserId,
        float $ratingWeight = 1.0,
        float $confidenceWeight = 1.0,
        ?DateTimeInterface $occurredAt = null,
    ): array {
        $attribute = $this->loadAttribute($attributeId);

        $players = $this->loadPlayers([$winnerId, $loserId]);

        $winnerPlayer = $players[$winnerId] ?? null;
        $loserPlayer = $players[$loserId] ?? null;

        if (!$winnerPlayer || !$loserPlayer) {
            throw new RuntimeException('Player not found for duel vote application.');
        }

        $winnerPos = $this->posCode($winnerPlayer);
        $loserPos = $this->posCode($loserPlayer);

        $playerIds = [$winnerId, $loserId];
        sort($playerIds);

        foreach ($playerIds as $playerId) {
            $player = $players[$playerId] ?? null;

            if ($player === null) {
                throw new RuntimeException('Player not found for duel vote application.');
            }

            $this->firstOrCreateRatingRow(
                playerId: $playerId,
                attributeId: $attribute->id,
                attributeKey: $attribute->key,
                posCode: $this->posCode($player),
            );
        }

        $lockedRows = $this->lockRatingRowsForPlayers($attribute->id, $playerIds);

        if ($lockedRows->count() !== 2) {
            throw new RuntimeException('Expected two locked rating rows for duel vote application.');
        }

        $winnerRow = $lockedRows[$winnerId];
        $loserRow = $lockedRows[$loserId];

        $beforeWinner = (float) $winnerRow->rating;
        $beforeLoser = (float) $loserRow->rating;

        $n = ((int) $winnerRow->votes_count + (int) $loserRow->votes_count) + 1;

        $ratingWeight = max(0.0, (float) $ratingWeight);
        $confidenceWeight = max(0.0, (float) $confidenceWeight);

        $updated = $this->ratingService->updateRatingsFromVote(
            $beforeWinner,
            $beforeLoser,
            $winnerPos,
            $loserPos,
            1,
            $n,
            null,
            $ratingWeight
        );

        $afterWinner = (float) ($updated['ratingA'] ?? $updated[0] ?? $beforeWinner);
        $afterLoser = (float) ($updated['ratingB'] ?? $updated[1] ?? $beforeLoser);

        $voteAt = $this->normalizeOccurredAt($occurredAt);

        $this->persistRow(
            row: $winnerRow,
            afterRating: $afterWinner,
            ratingWeight: $ratingWeight,
            confidenceWeight: $confidenceWeight,
            occurredAt: $voteAt,
        );

        event(new PlayerAttributeRatingUpdated(
            playerId: $winnerId,
            attributeKey: $attribute->key,
            rating: $afterWinner,
            confidence: (float) $winnerRow->confidence,
        ));

        $this->persistRow(
            row: $loserRow,
            afterRating: $afterLoser,
            ratingWeight: $ratingWeight,
            confidenceWeight: $confidenceWeight,
            occurredAt: $voteAt,
        );

        event(new PlayerAttributeRatingUpdated(
            playerId: $loserId,
            attributeKey: $attribute->key,
            rating: $afterLoser,
            confidence: (float) $loserRow->confidence,
        ));

        $this->recalculatePlayerOverallAction->execute($winnerPlayer);
        $this->recalculatePlayerOverallAction->execute($loserPlayer);

        return [
            'winner_seed_pos' => $winnerPos,
            'loser_seed_pos' => $loserPos,
            'winner' => [
                'player_id' => $winnerId,
                'pre_rating' => $beforeWinner,
                'post_rating' => $afterWinner,
                'delta_rating' => $afterWinner - $beforeWinner,
                'votes_count' => (int) $winnerRow->votes_count,
                'rating_weight_sum' => (float) $winnerRow->rating_weight_sum,
                'confidence_weight_sum' => (float) $winnerRow->confidence_weight_sum,
                'confidence' => (float) $winnerRow->confidence,
            ],
            'loser' => [
                'player_id' => $loserId,
                'pre_rating' => $beforeLoser,
                'post_rating' => $afterLoser,
                'delta_rating' => $afterLoser - $beforeLoser,
                'votes_count' => (int) $loserRow->votes_count,
                'rating_weight_sum' => (float) $loserRow->rating_weight_sum,
                'confidence_weight_sum' => (float) $loserRow->confidence_weight_sum,
                'confidence' => (float) $loserRow->confidence,
            ],
        ];
    }

    public function executeDirect(
        int $playerId,
        int $attributeId,
        int $value,
        float $ratingWeight = 1.0,
        float $confidenceWeight = 1.0,
        ?DateTimeInterface $occurredAt = null,
    ): array {
        $attribute = $this->loadAttribute($attributeId);
        $player = $this->loadPlayer($playerId);
        $posCode = $this->posCode($player);

        $row = $this->firstOrCreateRatingRow(
            playerId: $playerId,
            attributeId: $attribute->id,
            attributeKey: $attribute->key,
            posCode: $posCode,
        );

        $ratingWeight = max(0.0, (float) $ratingWeight);
        $confidenceWeight = max(0.0, (float) $confidenceWeight);

        $beforeRating = (float) $row->rating;
        $beforeRatingWeightSum = (float) ($row->rating_weight_sum ?? 0);

        $newRatingWeightSum = $beforeRatingWeightSum + $ratingWeight;

        $afterRating = $newRatingWeightSum > 0
            ? (($beforeRating * $beforeRatingWeightSum) + ((float) $value * $ratingWeight)) / $newRatingWeightSum
            : $beforeRating;

        $afterRating = round(min(99.0, max(0.0, $afterRating)), 3);

        $voteAt = $this->normalizeOccurredAt($occurredAt);

        $this->persistRow(
            row: $row,
            afterRating: $afterRating,
            ratingWeight: $ratingWeight,
            confidenceWeight: $confidenceWeight,
            occurredAt: $voteAt,
        );

        event(new PlayerAttributeRatingUpdated(
            playerId: $playerId,
            attributeKey: $attribute->key,
            rating: $afterRating,
            confidence: (float) $row->confidence,
        ));

        $this->recalculatePlayerOverallAction->execute($player);

        return [
            'player_seed_pos' => $posCode,
            'player_id' => $playerId,
            'pre_rating_a' => round($beforeRating, 3),
            'post_rating_a' => $afterRating,
            'delta_rating_a' => round($afterRating - $beforeRating, 3),
            'votes_count' => (int) $row->votes_count,
            'rating_weight_sum' => (float) $row->rating_weight_sum,
            'confidence_weight_sum' => (float) $row->confidence_weight_sum,
            'confidence' => (float) $row->confidence,
        ];
    }

    private function loadAttribute(int $attributeId): Attribute
    {
        return Attribute::query()
            ->select('id', 'key')
            ->findOrFail($attributeId);
    }

    private function loadPlayer(int $playerId): Player
    {
        return Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with([
                'positionRef:id,short_label,key,label,group',
                'fdPositionRef:id,short_label,key,label,group',
                'manualPositionRef:id,short_label,key,label,group',
            ])
            ->whereKey($playerId)
            ->firstOrFail();
    }

    private function loadPlayers(array $playerIds)
    {
        return Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with([
                'positionRef:id,short_label,key,label,group',
                'fdPositionRef:id,short_label,key,label,group',
                'manualPositionRef:id,short_label,key,label,group',
            ])
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');
    }

    private function posCode(Player $player): string
    {
        $code = $player->effective_position_short
            ?? $player->effective_position_key
            ?? $player->effective_position_label
            ?? 'ST';

        return strtoupper((string) $code);
    }

    private function firstOrCreateRatingRow(
        int $playerId,
        int $attributeId,
        string $attributeKey,
        string $posCode,
    ): PlayerAttributeRating {
        return PlayerAttributeRating::query()->firstOrCreate(
            ['player_id' => $playerId, 'attribute_id' => $attributeId],
            [
                'rating' => Seed::for($posCode, $attributeKey),
                'rating_weight_sum' => 0,
                'confidence_weight_sum' => 0,
                'confidence' => 0,
                'votes_count' => 0,
                'last_vote_at' => null,
            ]
        );
    }

    /**
     * @param  list<int>  $playerIds  Must already be sorted ascending by player_id.
     */
    private function lockRatingRowsForPlayers(int $attributeId, array $playerIds): \Illuminate\Support\Collection
    {
        return PlayerAttributeRating::query()
            ->where('attribute_id', $attributeId)
            ->whereIn('player_id', $playerIds)
            ->orderBy('player_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('player_id');
    }

    private function persistRow(
        PlayerAttributeRating $row,
        float $afterRating,
        float $ratingWeight,
        float $confidenceWeight,
        Carbon $occurredAt,
    ): void {
        $row->rating = $afterRating;
        $row->votes_count = ((int) $row->votes_count) + 1;
        $row->rating_weight_sum = ((float) ($row->rating_weight_sum ?? 0)) + $ratingWeight;
        $row->confidence_weight_sum = ((float) ($row->confidence_weight_sum ?? 0)) + $confidenceWeight;
        $row->confidence = min(100.0, round((float) $row->confidence_weight_sum, 2));
        $row->last_vote_at = $occurredAt;
        $row->save();
    }

    private function normalizeOccurredAt(?DateTimeInterface $occurredAt): Carbon
    {
        if ($occurredAt === null) {
            return Carbon::now();
        }

        return Carbon::instance($occurredAt);
    }
}
