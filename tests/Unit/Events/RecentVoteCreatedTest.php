<?php

namespace Tests\Unit\Events;

use App\Events\RecentVoteCreated;
use Illuminate\Broadcasting\Channel;
use Tests\TestCase;

class RecentVoteCreatedTest extends TestCase
{
    public function test_it_broadcasts_on_live_channel_with_flat_item_payload(): void
    {
        $item = [
            'id' => '123',
            'leftPlayer' => 'Bukayo Saka',
            'rightPlayer' => 'Cole Palmer',
            'leftPlayerId' => 10,
            'rightPlayerId' => 20,
            'winnerPlayerId' => 10,
            'attributeKey' => 'dribbling',
            'attributeLabel' => 'Dribbling',
        ];

        $event = new RecentVoteCreated($item);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('live', $channels[0]->name);
        $this->assertSame('live.recent-vote.created', $event->broadcastAs());
        $this->assertSame($item, $event->broadcastWith());
    }
}
