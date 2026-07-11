<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RebuildAttributeRankingProjectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rebuilds_zset_and_meta_hash_from_player_attribute_ratings(): void
    {
        $positionId = $this->createPosition([
            'short_label' => 'ST',
            'key' => 'st',
            'label' => 'Striker',
            'group' => 'ATTACK',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Test Striker',
            'slug' => 'test-striker',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PHYSICAL',
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $playerId,
            'attribute_id' => $attributeId,
            'rating' => 88.75,
            'votes_count' => 5,
            'rating_weight_sum' => 5,
            'confidence_weight_sum' => 84.25,
            'confidence' => 84.25,
            'last_vote_at' => now(),
        ]);

        Redis::shouldReceive('keys')
            ->once()
            ->with('ranking:*')
            ->andReturn([
                'laravel_database_ranking:pace',
                'laravel_database_ranking:pace:meta',
            ]);

        Redis::shouldReceive('del')
            ->once()
            ->with('ranking:pace')
            ->andReturn(1);

        Redis::shouldReceive('del')
            ->once()
            ->with('ranking:pace:meta')
            ->andReturn(1);

        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:pace', 88.75, (string) $playerId)
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:pace:meta', (string) $playerId, '{"confidence":84.25}')
            ->andReturn(1);

        $this->artisan('app:rebuild-attribute-ranking-projection-command')
            ->assertExitCode(0);
    }

    private function createPosition(array $data): int
    {
        return (int) DB::table('positions')->insertGetId(array_merge([
            'created_at' => now(),
            'updated_at' => now(),
        ], $data));
    }

    private function createPlayer(array $data): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'position_id' => $data['position_id'],
            'club_id' => null,
            'country_id' => null,
            'number' => null,
            'date_of_birth' => null,
        ]);
    }

    private function createAttribute(array $data): int
    {
        return (int) DB::table('attributes')->insertGetId([
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'scope' => $data['scope'],
        ]);
    }
}
