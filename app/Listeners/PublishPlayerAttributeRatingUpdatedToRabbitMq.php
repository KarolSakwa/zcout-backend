<?php

namespace App\Listeners;

use App\Events\PlayerAttributeRatingUpdated;
use App\Services\RabbitMq\RabbitMqPublisher;
use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class PublishPlayerAttributeRatingUpdatedToRabbitMq implements ShouldHandleEventsAfterCommit
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly RabbitMqPublisher $publisher,
        private readonly AttributeRankingProjectionWriter $projectionWriter,
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(PlayerAttributeRatingUpdated $event): void
    {
        Log::info('PlayerAttributeRatingUpdated listener entered');

        $this->projectionWriter->upsert(
            $event->attributeKey,
            $event->playerId,
            $event->rating,
            $event->confidence,
        );

        $this->publisher->publish(
            exchange: 'zcout.events',
            routingKey: 'player.attribute.updated',
            payload: [
                'player_id' => $event->playerId,
                'attribute_key' => $event->attributeKey,
                'rating' => $event->rating,
                'confidence' => $event->confidence,
            ],
        );
    }
}
