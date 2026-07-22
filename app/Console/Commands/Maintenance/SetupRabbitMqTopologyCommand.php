<?php

namespace App\Console\Commands\Maintenance;

use App\Services\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;

class SetupRabbitMqTopologyCommand extends Command
{
    protected $signature = 'app:setup-rabbit-mq-topology-command';

    protected $description = 'Command description';

    public function handle(
        RabbitMqConnection $rabbitMqConnection
    ): int {
        $connection = $rabbitMqConnection->create();

        $channel = $connection->channel();

        $channel->exchange_declare(
            exchange: 'zcout.events',
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false,
        );

        $channel->queue_declare(
            queue: 'ranking.projections',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false,
        );

        $channel->queue_declare(
            queue: 'attribute.projections',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false,
        );

        $channel->queue_bind(
            queue: 'attribute.projections',
            exchange: 'zcout.events',
            routing_key: 'player.attribute.updated',
        );

        $channel->queue_bind(
            queue: 'ranking.projections',
            exchange: 'zcout.events',
            routing_key: 'player.overall.updated',
        );

        $channel->close();

        $connection->close();

        return self::SUCCESS;
    }
}
