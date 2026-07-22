<?php

namespace App\Console\Commands\Rankings;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ShowAttributeRankingCommand extends Command
{
    protected $signature = 'app:show-attribute-ranking-command {attributeKey}';

    protected $description = 'Show attribute ranking from Redis';

    public function handle(): int
    {
        $attributeKey = $this->argument('attributeKey');

        $ranking = Redis::zrevrange(
            'ranking:' . $attributeKey,
            0,
            9,
            ['withscores' => true]
        );

        dd($ranking);

        return self::SUCCESS;
    }
}
