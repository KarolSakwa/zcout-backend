<?php

namespace App\Console\Commands;

use App\Actions\RecalculatePlayerOverallAction;
use App\Models\Player;
use Illuminate\Console\Command;

class RecalculatePlayerOverallsCommand extends Command
{
    protected $signature = 'zcout:recalculate-player-overalls';

    protected $description = 'Recalculate persisted player overalls';

    public function handle(RecalculatePlayerOverallAction $action): int
    {
        $count = 0;

        Player::query()
            ->chunk(200, function ($players) use ($action, &$count) {
                foreach ($players as $player) {
                    $action->execute($player);
                    $count++;
                }
            });

        $this->info("Recalculated {$count} player overalls.");

        return self::SUCCESS;
    }
}
