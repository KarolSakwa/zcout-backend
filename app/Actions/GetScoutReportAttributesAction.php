<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\ScoutReportSkip;
use App\Models\Vote;
use App\Support\Seed;

final class GetScoutReportAttributesAction
{
    public function execute(int $userId, int $playerId): array
    {
        $player = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($playerId)
            ->first();

        if (!$player) {
            return [
                'ok' => false,
                'status' => 404,
                'body' => ['message' => 'Player not found.'],
            ];
        }

        $posCode = strtoupper((string) ($player->positionRef?->short_label ?? ''));
        $isGk = $posCode === 'GK';

        $votedAttributeIds = Vote::query()
            ->where('source', 'direct')
            ->where('user_id', $userId)
            ->where('player_a_id', $playerId)
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $skippedAttributeIds = ScoutReportSkip::query()
            ->where('user_id', $userId)
            ->where('player_id', $playerId)
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $attributes = Attribute::query()
            ->select('id', 'key', 'label', 'group', 'scope')
            ->when(
                $isGk,
                fn ($q) => $q->whereIn('scope', ['both', 'gk']),
                fn ($q) => $q->where('scope', 'both')
            )
            ->when(
                count($votedAttributeIds) > 0,
                fn ($q) => $q->whereNotIn('id', $votedAttributeIds)
            )
            ->get();

        $ratingRows = PlayerAttributeRating::query()
            ->where('player_id', $playerId)
            ->whereIn('attribute_id', $attributes->pluck('id'))
            ->get()
            ->keyBy('attribute_id');

        $items = $attributes
            ->map(function (Attribute $attribute) use ($playerId, $posCode, $ratingRows, $skippedAttributeIds) {
                $row = $ratingRows->get($attribute->id);

                return [
                    'id' => (int) $attribute->id,
                    'key' => (string) $attribute->key,
                    'label' => (string) $attribute->label,
                    'group' => (string) $attribute->group,
                    'scope' => (string) $attribute->scope,
                    'is_skipped' => in_array((int) $attribute->id, $skippedAttributeIds, true),
                    'relevance_score' => $this->relevanceScore($posCode, (string) $attribute->key),
                    'confidence' => $row ? (float) ($row->confidence ?? 0) : 0.0,
                    'crowd_rating' => $row ? (float) $row->rating : (float) Seed::for($posCode, (string) $attribute->key),
                ];
            })
            ->sort(function (array $a, array $b) {
                $cmp = ($a['is_skipped'] <=> $b['is_skipped']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $cmp = ($b['relevance_score'] <=> $a['relevance_score']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $cmp = ($a['confidence'] <=> $b['confidence']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($a['key'], $b['key']);
            })
            ->take(6)
            ->values()
            ->map(fn (array $item) => [
                'id' => $item['id'],
                'key' => $item['key'],
                'label' => $item['label'],
                'group' => $item['group'],
                'is_skipped' => $item['is_skipped'],
                'description' => (string) config("attribute_descriptions.{$item['key']}", ''),
            ])
            ->all();

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'player_id' => $playerId,
                'items' => $items,
            ],
        ];
    }

    private function relevanceScore(string $posCode, string $attributeKey): int
    {
        return (int) config(
            "scout_report.position_relevance.{$posCode}.{$attributeKey}",
            (int) config('scout_report.default_relevance', 10),
        );
    }
}
