<?php

namespace App\Console\Commands;

use App\Services\Ranking\RebuildRankingProjectionsAction;
use Illuminate\Console\Command;

class RebuildOverallRankingProjectionCommand extends Command
{
    protected $signature = 'app:rebuild-overall-ranking-projection-command';

    protected $description = 'Rebuild overall ranking projection for the active Premier League pool';

    public function handle(RebuildRankingProjectionsAction $rebuildRankingProjectionsAction): int
    {
        $rebuildRankingProjectionsAction->rebuildOverallProjection();

        $this->info('Overall ranking projection rebuilt');

        return self::SUCCESS;
    }
}
