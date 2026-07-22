<?php

namespace App\Console\Commands\Rankings;

use App\Services\RabbitMq\RabbitMqConnection;
use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeAttributeProjectionQueueCommand extends Command
{
    protected $signature = 'app:consume-attribute-projection-queue-command';

    protected $description = 'Command description';

    public function handle(
        RabbitMqConnection $rabbitMqConnection,
        AttributeRankingProjectionWriter $projectionWriter,
    ): int
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
            'attribute.projections',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($projectionWriter) {
                $this->info($message->getBody());

                $payload = json_decode(
                    $message->getBody(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $projectionWriter->upsert(
                    $payload['attribute_key'],
                    $payload['player_id'],
                    (float) $payload['rating'],
                    (float) $payload['confidence'],
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
