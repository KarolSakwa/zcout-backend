<?php

namespace App\Listeners;

use App\Events\PlayerOverallUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class PublishPlayerOverallUpdatedToRabbitMq
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PlayerOverallUpdated $event): void
    {
        Log::info('PlayerOverallUpdated listener fired', [
            'player_id' => $event->playerId,
            'overall' => $event->overall,
            'confidence' => $event->confidence,
        ]);
    }
}
