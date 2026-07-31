<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RankingPositionFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_filter_uses_effective_position_not_fd_override(): void
    {
        $fixture = $this->seedPositionFilterFixture();

        $response = $this->getJson('/api/rankings/overall?position=DM&limit=25&page=1');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('total_pages', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.player.id', $fixture['display_dm_player_id'])
            ->assertJsonPath('items.0.pos', 'DM');

        $ids = collect($response->json('items'))->pluck('player.id')->all();

        $this->assertContains($fixture['display_dm_player_id'], $ids);
        $this->assertNotContains($fixture['display_cm_player_id'], $ids);
    }

    public function test_position_filter_total_and_pagination_cover_full_filtered_pool(): void
    {
        $fixture = $this->seedPaginatedDmFixture(playerCount: 30, limit: 10);

        $page1 = $this->getJson('/api/rankings/overall?position=DM&limit=10&page=1');
        $page2 = $this->getJson('/api/rankings/overall?position=DM&limit=10&page=2');
        $page3 = $this->getJson('/api/rankings/overall?position=DM&limit=10&page=3');

        $page1
            ->assertOk()
            ->assertJsonPath('total', 30)
            ->assertJsonPath('total_pages', 3)
            ->assertJsonPath('filters.page', 1)
            ->assertJsonCount(10, 'items');

        $page2
            ->assertOk()
            ->assertJsonPath('total', 30)
            ->assertJsonPath('total_pages', 3)
            ->assertJsonPath('filters.page', 2)
            ->assertJsonCount(10, 'items');

        $page3
            ->assertOk()
            ->assertJsonPath('total', 30)
            ->assertJsonPath('total_pages', 3)
            ->assertJsonPath('filters.page', 3)
            ->assertJsonCount(10, 'items');

        $allIds = collect([
            ...collect($page1->json('items'))->pluck('player.id'),
            ...collect($page2->json('items'))->pluck('player.id'),
            ...collect($page3->json('items'))->pluck('player.id'),
        ]);

        $this->assertCount(30, $allIds->unique());
        $this->assertSame($fixture['dm_player_ids'], $allIds->sort()->values()->all());
    }

    public function test_position_filter_applies_before_pagination_for_attribute_ranking(): void
    {
        $fixture = $this->seedPaginatedDmFixture(playerCount: 12, limit: 5, attributeKey: 'pace');

        $response = $this->getJson('/api/rankings/pace?position=DM&limit=5&page=2');

        $response
            ->assertOk()
            ->assertJsonPath('attribute.key', 'pace')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('total_pages', 3)
            ->assertJsonPath('filters.page', 2)
            ->assertJsonCount(5, 'items');

        $pageIds = collect($response->json('items'))->pluck('player.id')->all();
        $expectedPageIds = array_slice($fixture['dm_player_ids'], 5, 5);

        $this->assertSame($expectedPageIds, $pageIds);
    }

    /**
     * @return array{
     *     display_dm_player_id: int,
     *     display_cm_player_id: int
     * }
     */
    private function seedPositionFilterFixture(): array
    {
        $now = now();

        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Ranking Position Club',
            'slug' => 'ranking-position-club',
            'is_current_premier_league' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dmId = $this->insertPosition('DM', 'Defensive Midfielder');
        $cmId = $this->insertPosition('CM', 'Central Midfielder');

        $displayDmPlayerId = DB::table('players')->insertGetId([
            'name' => 'Displayed DM',
            'slug' => 'displayed-dm',
            'club_id' => $clubId,
            'position_id' => $dmId,
            'fd_position_id' => $cmId,
        ]);

        $displayCmPlayerId = DB::table('players')->insertGetId([
            'name' => 'Displayed CM',
            'slug' => 'displayed-cm',
            'club_id' => $clubId,
            'position_id' => $cmId,
            'fd_position_id' => $dmId,
        ]);

        foreach ([$displayDmPlayerId, $displayCmPlayerId] as $playerId) {
            DB::table('player_overalls')->insert([
                'player_id' => $playerId,
                'position' => 'DM',
                'overall' => 70,
                'confidence' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'display_dm_player_id' => $displayDmPlayerId,
            'display_cm_player_id' => $displayCmPlayerId,
        ];
    }

    /**
     * @return array{dm_player_ids: int[]}
     */
    private function seedPaginatedDmFixture(int $playerCount, int $limit, string $attributeKey = 'overall'): array
    {
        $now = now();

        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Ranking Pagination Club',
            'slug' => 'ranking-pagination-club',
            'is_current_premier_league' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dmId = $this->insertPosition('DM', 'Defensive Midfielder');
        $cmId = $this->insertPosition('CM', 'Central Midfielder');

        $attributeId = null;

        if ($attributeKey !== 'overall') {
            $attributeId = DB::table('attributes')->insertGetId([
                'key' => $attributeKey,
                'label' => ucfirst($attributeKey),
                'group' => 'PACE',
                'scope' => 'both',
            ]);
        }

        $dmPlayerIds = [];

        for ($i = 1; $i <= $playerCount; $i++) {
            $playerId = DB::table('players')->insertGetId([
                'name' => "DM Player {$i}",
                'slug' => "dm-player-{$i}",
                'club_id' => $clubId,
                'position_id' => $dmId,
                'fd_position_id' => $cmId,
            ]);

            $dmPlayerIds[] = $playerId;

            DB::table('player_overalls')->insert([
                'player_id' => $playerId,
                'position' => 'DM',
                'overall' => 90 - $i,
                'confidence' => 10 + $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($attributeId !== null) {
                DB::table('player_attribute_ratings')->insert([
                    'player_id' => $playerId,
                    'attribute_id' => $attributeId,
                    'rating' => 90 - $i,
                    'votes_count' => 1,
                    'confidence' => 10 + $i,
                ]);
            }
        }

        return [
            'dm_player_ids' => $dmPlayerIds,
        ];
    }

    private function insertPosition(string $shortLabel, string $label): int
    {
        $now = now();

        return DB::table('positions')->insertGetId([
            'short_label' => $shortLabel,
            'key' => $shortLabel,
            'label' => $label,
            'group' => 'MID',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
