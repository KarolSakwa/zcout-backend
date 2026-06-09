<?php

namespace App\Services\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnection
{
    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: env('RABBITMQ_HOST'),
            port: (int) env('RABBITMQ_PORT', 5672),
            user: env('RABBITMQ_USER'),
            password: env('RABBITMQ_PASSWORD'),
            vhost: env('RABBITMQ_VHOST', '/'),
        );
    }
}
