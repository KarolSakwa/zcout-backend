<?php

namespace App\Actions;

use App\Models\Player;
use App\Models\PlayerOverall;
use App\Services\Ranking\AttributeRankingService;

final class ResolveFeaturedPlayerAction
{
    public function __construct(
        private readonly AttributeRankingService $attributeRankingService,
    ) {}

    /**
     * @return array{player: Player, rank: ?int}
     */
    public function execute(): array
    {
        $player = Player::query()
            ->inCurrentPremierLeague()
            ->join('player_reputation_stats as prs', 'prs.player_id', '=', 'players.id')
            ->where('prs.tier', 'A')
            ->inRandomOrder()
            ->select('players.*')
            ->firstOrFail();

        $overall = PlayerOverall::query()
            ->where('player_id', $player->id)
            ->first();

        $rank = null;

        if ($overall) {
            $rank = $this->attributeRankingService->getRank(
                'overall',
                $player->id,
            );
        }

        return [
            'player' => $player,
            'rank' => $rank,
        ];
    }
}
