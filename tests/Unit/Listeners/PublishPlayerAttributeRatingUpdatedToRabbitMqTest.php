<?php

namespace Tests\Unit\Listeners;

use App\Events\PlayerAttributeRatingUpdated;
use App\Listeners\PublishPlayerAttributeRatingUpdatedToRabbitMq;
use App\Services\RabbitMq\RabbitMqPublisher;
use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class PublishPlayerAttributeRatingUpdatedToRabbitMqTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_deferred_until_database_transaction_commits(): void
    {
        $this->assertContains(
            ShouldHandleEventsAfterCommit::class,
            class_implements(PublishPlayerAttributeRatingUpdatedToRabbitMq::class),
        );
    }

    public function test_it_writes_redis_projection_and_publishes_to_rabbitmq(): void
    {
        $playerId = $this->createActivePremierLeaguePlayer();

        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')
            ->once()
            ->with(
                'zcout.events',
                'player.attribute.updated',
                [
                    'player_id' => $playerId,
                    'attribute_key' => 'leadership',
                    'rating' => 91.0,
                    'confidence' => 12.5,
                ],
            );

        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:leadership', 91.0, (string) $playerId)
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:leadership:meta', (string) $playerId, '{"confidence":12.5}')
            ->andReturn(1);

        $listener = new PublishPlayerAttributeRatingUpdatedToRabbitMq(
            $publisher,
            new AttributeRankingProjectionWriter(),
        );

        $listener->handle(new PlayerAttributeRatingUpdated(
            playerId: $playerId,
            attributeKey: 'leadership',
            rating: 91.0,
            confidence: 12.5,
        ));
    }

    private function createActivePremierLeaguePlayer(): int
    {
        $clubId = (int) DB::table('clubs')->insertGetId([
            'name' => 'Listener Club',
            'slug' => 'listener-club-'.uniqid(),
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('players')->insertGetId([
            'name' => 'Listener Player',
            'slug' => 'listener-player-'.uniqid(),
            'club_id' => $clubId,
        ]);
    }
}
