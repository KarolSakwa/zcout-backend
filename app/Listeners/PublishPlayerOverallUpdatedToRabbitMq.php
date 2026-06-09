<?php

namespace App\Listeners;

use App\Events\PlayerOverallUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use App\Services\RabbitMq\RabbitMqPublisher;

class PublishPlayerOverallUpdatedToRabbitMq
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
    public function handle(PlayerOverallUpdated $event): void
    {
        Log::info('PlayerOverallUpdated listener entered');

        $this->publisher->publish(
            exchange: 'zcout.events',
            routingKey: 'player.overall.updated',
            payload: [
                'player_id' => $event->playerId,
                'overall' => $event->overall,
                'confidence' => $event->confidence,
            ],
        );
    }
}
