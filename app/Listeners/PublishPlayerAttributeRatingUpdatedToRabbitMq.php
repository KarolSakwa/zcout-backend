<?php

namespace App\Listeners;

use App\Events\PlayerAttributeRatingUpdated;
use App\Services\RabbitMq\RabbitMqPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class PublishPlayerAttributeRatingUpdatedToRabbitMq
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly RabbitMqPublisher $publisher,
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(PlayerAttributeRatingUpdated $event): void
    {
        Log::info('PlayerAttributeRatingUpdated listener entered');

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
