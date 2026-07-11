<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Services\Ranking\AttributeRankingService;
use App\Support\Attributes\AttributeAssetPaths;
use App\Support\Homepage\FeaturedRankingPlayerPayload;
use Illuminate\Support\Facades\DB;

final class BuildFeaturedRankingPayloadAction
{
    private const TOP_LIMIT = 5;

    public function __construct(
        private ResolveFeaturedRankingAttributeAction $resolveFeaturedRankingAttributeAction,
        private AttributeRankingService $attributeRankingService,
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
            self::TOP_LIMIT,
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
            ->whereIn('p.id', $playerIds)
            ->get([
                'p.id as player_id',
                DB::raw('COALESCE(p.manual_display_name, p.fd_name, p.name) as player_name'),
            ])
            ->keyBy('player_id');

        $players = [];

        foreach ($rankingEntries as $entry) {
            $playerRow = $playerRows->get($entry['player_id']);

            if (!$playerRow) {
                continue;
            }

            $players[] = FeaturedRankingPlayerPayload::fromParts(
                playerId: (int) $playerRow->player_id,
                playerName: (string) $playerRow->player_name,
                rating: (float) $entry['rating'],
                confidence: $entry['confidence'],
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
