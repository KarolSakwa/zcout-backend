<?php

namespace App\Simulation\Actions;

use Illuminate\Support\Facades\DB;

final class ResetSimulationState
{
    public function handle(): void
    {
        DB::statement('TRUNCATE TABLE votes, duels, player_attribute_ratings RESTART IDENTITY CASCADE');
    }
}
