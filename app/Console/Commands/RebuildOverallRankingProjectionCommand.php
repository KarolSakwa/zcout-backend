<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlayerOverall;
use Illuminate\Support\Facades\Redis;

class RebuildOverallRankingProjectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rebuild-overall-ranking-projection-command';

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
        Redis::del('ranking:overall');

        PlayerOverall::query()
            ->select('player_id', 'overall')
            ->get()
            ->each(function (PlayerOverall $playerOverall) {
                Redis::zadd(
                    'ranking:overall',
                    (float) $playerOverall->overall,
                    (string) $playerOverall->player_id,
                );
            });

        $this->info('Overall ranking projection rebuilt');

        return self::SUCCESS;
    }
}
