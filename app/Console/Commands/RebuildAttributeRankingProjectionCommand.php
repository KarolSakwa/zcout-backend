<?php

namespace App\Console\Commands;

use App\Services\Ranking\RebuildRankingProjectionsAction;
use Illuminate\Console\Command;

class RebuildAttributeRankingProjectionCommand extends Command
{
    protected $signature = 'app:rebuild-attribute-ranking-projection-command';

    protected $description = 'Rebuild attribute ranking projections for the active Premier League pool';

    public function handle(RebuildRankingProjectionsAction $rebuildRankingProjectionsAction): int
    {
        $rebuildRankingProjectionsAction->rebuildAttributeProjections();

        $this->info('Attribute ranking projections rebuilt');

        return self::SUCCESS;
    }
}
