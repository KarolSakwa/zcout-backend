<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RabbitMq\RabbitMqPublisher;

class RabbitMqSmokeTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rabbit-mq-smoke-test-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(RabbitMqPublisher $publisher): int
    {
        $publisher->publish(
            exchange: 'zcout.events',
            routingKey: 'test.message',
            payload: [
                'message' => 'hello rabbitmq',
                'timestamp' => now()->toIso8601String(),
            ],
        );

        $this->info('Message published');

        return self::SUCCESS;
    }
}
