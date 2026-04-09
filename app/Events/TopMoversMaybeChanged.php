<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TopMoversMaybeChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function broadcastOn(): array
    {
        return [
            new Channel('live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live.top-movers.maybe-changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
