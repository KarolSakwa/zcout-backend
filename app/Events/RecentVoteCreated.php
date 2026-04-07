<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecentVoteCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public array $item,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live.recent-vote.created';
    }

    public function broadcastWith(): array
    {
        return $this->item;
    }
}
