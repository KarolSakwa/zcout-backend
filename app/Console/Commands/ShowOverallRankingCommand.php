<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ShowOverallRankingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:show-overall-ranking-command {playerId?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $playerId = $this->argument('playerId');

        if ($playerId) {
            dd(
                Redis::zrevrank(
                    'ranking:overall',
                    (string) $playerId,
                )
            );
        }

        $ranking = Redis::zrevrange(
            'ranking:overall',
            0,
            9,
            ['withscores' => true]
        );

        dd($ranking);

        return self::SUCCESS;
    }
}
