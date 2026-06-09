<?php

namespace App\Services\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;

class RabbitMqPublisher
{
    public function __construct(
        private readonly RabbitMqConnection $connection,
    ) {
    }

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $connection = $this->connection->create();

        $channel = $connection->channel();

        $channel->exchange_declare(
            exchange: $exchange,
            type: 'topic',
            passive: false,
            durable: true,
            auto_delete: false,
        );

        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
            ]
        );

        $channel->basic_publish(
            msg: $message,
            exchange: $exchange,
            routing_key: $routingKey,
        );

        Log::info('RabbitMQ publish executed', [
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        $channel->close();
        $connection->close();
    }
}
