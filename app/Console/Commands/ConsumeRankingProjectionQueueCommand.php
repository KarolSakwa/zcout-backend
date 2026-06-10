<?php

namespace App\Console\Commands;

use App\Services\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeRankingProjectionQueueCommand extends Command
{
    protected $signature = 'app:consume-ranking-projection-queue-command';

    protected $description = 'Command description';

    public function handle(RabbitMqConnection $rabbitMqConnection): int
    {
        $connection = $rabbitMqConnection->create();

        $channel = $connection->channel();

        $channel->basic_qos(
            0,
            1,
            false,
        );

        $this->info('Waiting for messages...');

        $channel->basic_consume(
            'ranking.projections',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) {
                $this->info($message->getBody());

                $payload = json_decode(
                    $message->getBody(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                Redis::zadd(
                    'ranking:overall',
                    (float) $payload['overall'],
                    (string) $payload['player_id'],
                );

                $message->ack();

                $this->info('Message processed');
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        return self::SUCCESS;
    }
}
