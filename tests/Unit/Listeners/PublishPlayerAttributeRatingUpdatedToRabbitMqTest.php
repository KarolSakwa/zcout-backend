<?php

namespace Tests\Unit\Listeners;

use App\Events\PlayerAttributeRatingUpdated;
use App\Listeners\PublishPlayerAttributeRatingUpdatedToRabbitMq;
use App\Services\RabbitMq\RabbitMqPublisher;
use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class PublishPlayerAttributeRatingUpdatedToRabbitMqTest extends TestCase
{
    public function test_it_is_deferred_until_database_transaction_commits(): void
    {
        $this->assertContains(
            ShouldHandleEventsAfterCommit::class,
            class_implements(PublishPlayerAttributeRatingUpdatedToRabbitMq::class),
        );
    }

    public function test_it_writes_redis_projection_and_publishes_to_rabbitmq(): void
    {
        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')
            ->once()
            ->with(
                'zcout.events',
                'player.attribute.updated',
                [
                    'player_id' => 42,
                    'attribute_key' => 'leadership',
                    'rating' => 91.0,
                    'confidence' => 12.5,
                ],
            );

        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:leadership', 91.0, '42')
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:leadership:meta', '42', '{"confidence":12.5}')
            ->andReturn(1);

        $listener = new PublishPlayerAttributeRatingUpdatedToRabbitMq(
            $publisher,
            new AttributeRankingProjectionWriter(),
        );

        $listener->handle(new PlayerAttributeRatingUpdated(
            playerId: 42,
            attributeKey: 'leadership',
            rating: 91.0,
            confidence: 12.5,
        ));
    }
}
