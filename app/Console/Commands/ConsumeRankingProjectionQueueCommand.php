<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RabbitMq\RabbitMqConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeRankingProjectionQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:consume-ranking-projection-queue-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(RabbitMqConnection $rabbitMqConnection): int
    {
        $connection = $rabbitMqConnection->create();

        $channel = $connection->channel();

        $message = $channel->basic_get('ranking.projections');

        if (!$message instanceof AMQPMessage) {
            $this->info('Queue is empty');

            $channel->close();
            $connection->close();

            return self::SUCCESS;
        }

        $this->info($message->getBody());

        $channel->basic_ack($message->getDeliveryTag());

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }
}
