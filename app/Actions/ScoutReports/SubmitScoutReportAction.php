<?php

namespace App\Actions\ScoutReports;

use App\Actions\Ratings\StoreDirectVoteAction;
use App\Models\ScoutReportSkip;
use Illuminate\Support\Facades\DB;

final class SubmitScoutReportAction
{
    public function __construct(
        private readonly StoreDirectVoteAction $storeDirectVoteAction,
    ) {
    }

    public function execute(int $userId, array $data): array
    {
        $playerId = (int) $data['player_id'];
        $votes = $data['votes'] ?? [];
        $skippedAttributeIds = $data['skipped_attribute_ids'] ?? [];

        return DB::transaction(function () use ($userId, $playerId, $votes, $skippedAttributeIds) {
            $createdVoteIds = [];

            foreach ($votes as $voteData) {
                $result = $this->storeDirectVoteAction->execute([
                    'attribute_key' => $voteData['attribute_key'],
                    'player_id' => $playerId,
                    'value' => (int) $voteData['value'],
                ], $userId);

                if (($result['ok'] ?? false) !== true) {
                    return $result;
                }

                $createdVoteIds[] = (int) $result['body']['vote_id'];

                ScoutReportSkip::query()
                    ->where('user_id', $userId)
                    ->where('player_id', $playerId)
                    ->where('attribute_id', (int) $result['body']['attribute_id'])
                    ->delete();
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
                    'created_vote_ids' => $createdVoteIds,
                    'votes_created' => count($createdVoteIds),
                    'skipped_attribute_ids' => array_values(array_map('intval', $skippedAttributeIds)),
                ],
            ];
        });
    }
}
