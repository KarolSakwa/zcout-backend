<?php

namespace App\Actions\Rankings;

use App\Models\Attribute;
use App\Services\Ranking\AttributeRankingService;
use Illuminate\Support\Facades\DB;

final class BuildAttributeTopPlayersAction
{
    private const TOP_LIMIT = 5;

    public function __construct(
        private AttributeRankingService $attributeRankingService,
    ) {}

    /**
     * @param  list<int>  $excludePlayerIds
     * @return array{attribute: array{key: string, label: string}|null, players: list<array{id: string, playerId: int, player: string, rating: float, rank: int}>}
     */
    public function execute(string $attributeKey, array $excludePlayerIds = []): array
    {
        $attribute = Attribute::query()->where('key', $attributeKey)->first();

        if (!$attribute) {
            return [
                'attribute' => null,
                'players' => [],
            ];
        }

        $excludeSet = array_fill_keys(
            array_map(static fn ($id): int => (int) $id, $excludePlayerIds),
            true,
        );

        $fetchLimit = self::TOP_LIMIT + count($excludeSet) + 10;
        $rankingEntries = $this->attributeRankingService->getTopPlayers(
            $attribute->key,
            max($fetchLimit, 20),
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

        $players = [];
        $rank = 0;

        foreach ($rankingEntries as $entry) {
            $playerId = (int) $entry['player_id'];

            if (isset($excludeSet[$playerId])) {
                continue;
            }

            $playerRow = $playerRows->get($playerId);

            if (!$playerRow) {
                continue;
            }

            $rank++;

            $players[] = [
                'id' => (string) $playerId,
                'playerId' => $playerId,
                'player' => (string) $playerRow->player_name,
                'rating' => round((float) $entry['rating'], 2),
                'rank' => $rank,
            ];

            if (count($players) >= self::TOP_LIMIT) {
                break;
            }
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
        ];
    }
}
