<?php

namespace App\Actions\ScoutReports;

use App\Actions\Ratings\RecalculatePlayerOverallAction;
use App\Actions\Ratings\StoreDirectVoteAction;
use App\Exceptions\ScoutReportSubmitFailedException;
use App\Models\Player;
use App\Models\PlayerOverall;
use App\Models\ScoutReportSkip;
use App\Models\ScoutReportSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubmitScoutReportAction
{
    public function __construct(
        private readonly StoreDirectVoteAction $storeDirectVoteAction,
        private readonly RecalculatePlayerOverallAction $recalculatePlayerOverallAction,
    ) {
    }

    public function execute(int $userId, array $data): array
    {
        $playerId = (int) $data['player_id'];
        $votes = $data['votes'] ?? [];
        $skippedAttributeIds = $data['skipped_attribute_ids'] ?? [];

        return DB::transaction(function () use ($userId, $playerId, $votes, $skippedAttributeIds) {
            $submissionId = null;
            $createdVoteIds = [];

            if ($votes !== []) {
                $player = Player::query()->findOrFail($playerId);
                $preOverall = $this->resolveCurrentOverall($player);

                $submissionId = (string) Str::uuid();
                $occurredAt = now();

                ScoutReportSubmission::query()->create([
                    'id' => $submissionId,
                    'user_id' => $userId,
                    'player_id' => $playerId,
                    'ratings_count' => 0,
                    'pre_overall' => $preOverall,
                    'post_overall' => null,
                    'created_at' => $occurredAt,
                ]);

                foreach ($votes as $voteData) {
                    $result = $this->storeDirectVoteAction->execute([
                        'attribute_key' => $voteData['attribute_key'],
                        'player_id' => $playerId,
                        'value' => (int) $voteData['value'],
                        'scout_report_submission_id' => $submissionId,
                    ], $userId);

                    if (($result['ok'] ?? false) !== true) {
                        throw new ScoutReportSubmitFailedException(
                            (int) ($result['status'] ?? 500),
                            (array) ($result['body'] ?? ['message' => 'Scout report submit failed.']),
                        );
                    }

                    $createdVoteIds[] = (int) $result['body']['vote_id'];

                    ScoutReportSkip::query()
                        ->where('user_id', $userId)
                        ->where('player_id', $playerId)
                        ->where('attribute_id', (int) $result['body']['attribute_id'])
                        ->delete();
                }

                $ratingsCount = count($createdVoteIds);

                if ($ratingsCount === 0) {
                    throw new ScoutReportSubmitFailedException(500, [
                        'message' => 'Scout report submit created no votes.',
                    ]);
                }

                $postOverall = $this->resolveCurrentOverall($player->fresh());

                ScoutReportSubmission::query()
                    ->whereKey($submissionId)
                    ->update([
                        'ratings_count' => $ratingsCount,
                        'post_overall' => $postOverall,
                    ]);
            }

            foreach ($skippedAttributeIds as $attributeId) {
                $attributeId = (int) $attributeId;

                $votedAttributeIds = collect($votes)
                    ->pluck('attribute_key')
                    ->filter()
                    ->values();

                $alreadyVotedThisSubmit = \App\Models\Attribute::query()
                    ->whereIn('key', $votedAttributeIds)
                    ->whereKey($attributeId)
                    ->exists();

                if ($alreadyVotedThisSubmit) {
                    continue;
                }

                ScoutReportSkip::query()->updateOrCreate(
                    [
                        'user_id' => $userId,
                        'player_id' => $playerId,
                        'attribute_id' => $attributeId,
                    ],
                    [
                        'skipped_at' => now(),
                    ]
                );
            }

            return [
                'ok' => true,
                'status' => 201,
                'body' => [
                    'player_id' => $playerId,
                    'submission_id' => $submissionId,
                    'created_vote_ids' => $createdVoteIds,
                    'votes_created' => count($createdVoteIds),
                    'skipped_attribute_ids' => array_values(array_map('intval', $skippedAttributeIds)),
                ],
            ];
        });
    }

    private function resolveCurrentOverall(Player $player): ?float
    {
        $overallRow = PlayerOverall::query()
            ->where('player_id', $player->id)
            ->first();

        if ($overallRow === null) {
            $this->recalculatePlayerOverallAction->execute($player);
            $overallRow = PlayerOverall::query()
                ->where('player_id', $player->id)
                ->first();
        }

        return $overallRow !== null
            ? round((float) $overallRow->overall, 3)
            : null;
    }
}
