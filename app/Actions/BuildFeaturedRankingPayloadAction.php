<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Services\Ranking\AttributeRankingService;
use App\Services\Ranking\AttributeRatingTrendService;
use App\Support\Attributes\AttributeAssetPaths;
use App\Support\Homepage\FeaturedRankingPlayerPayload;
use Illuminate\Support\Facades\DB;

final class BuildFeaturedRankingPayloadAction
{
    private const TOP_LIMIT = 5;

    public function __construct(
        private ResolveFeaturedRankingAttributeAction $resolveFeaturedRankingAttributeAction,
        private AttributeRankingService $attributeRankingService,
        private AttributeRatingTrendService $attributeRatingTrendService,
    ) {}

    public function execute(): array
    {
        $attribute = $this->resolveFeaturedRankingAttributeAction->execute();

        if (!$attribute) {
            return [
                'attribute' => null,
                'players' => [],
            ];
        }

        $rankingEntries = $this->attributeRankingService->getTopPlayers(
            $attribute->key,
            max(self::TOP_LIMIT * 10, 50),
        );

        if ($rankingEntries === []) {
            return [
                'attribute' => $this->attributePayload($attribute),
                'players' => [],
            ];
        }

        $playerIds = array_map(
            static fn (array $entry): int => $entry['player_id'],
            $rankingEntries,
        );

        $playerRows = DB::table('players as p')
            ->join('clubs as c', 'c.id', '=', 'p.club_id')
            ->where('c.is_current_premier_league', true)
            ->whereIn('p.id', $playerIds)
            ->get([
                'p.id as player_id',
                DB::raw('COALESCE(p.manual_display_name, p.fd_name, p.name) as player_name'),
            ])
            ->keyBy('player_id');

        $trendByPlayer = $this->attributeRatingTrendService->sumDeltasForAttribute(
            (int) $attribute->id,
            $playerIds,
        );

        $players = [];

        foreach ($rankingEntries as $entry) {
            if (count($players) >= self::TOP_LIMIT) {
                break;
            }

            $playerRow = $playerRows->get($entry['player_id']);

            if (!$playerRow) {
                continue;
            }

            $playerId = (int) $playerRow->player_id;

            $players[] = FeaturedRankingPlayerPayload::fromParts(
                playerId: $playerId,
                playerName: (string) $playerRow->player_name,
                rating: (float) $entry['rating'],
                confidence: $entry['confidence'],
                trend7d: isset($trendByPlayer[$playerId])
                    ? (float) $trendByPlayer[$playerId]
                    : null,
            );
        }

        return [
            'attribute' => $this->attributePayload($attribute),
            'players' => $players,
        ];
    }

    private function attributePayload(Attribute $attribute): array
    {
        return [
            'key' => (string) $attribute->key,
            'label' => (string) $attribute->label,
            'icon' => AttributeAssetPaths::icon((string) $attribute->key),
        ];
    }
}
