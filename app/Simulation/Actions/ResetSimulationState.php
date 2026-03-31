<?php

namespace App\Simulation\Actions;

use Illuminate\Support\Facades\DB;

final class ResetSimulationState
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            DB::table('votes')->delete();
            DB::table('duels')->delete();
            DB::table('player_attribute_ratings')->delete();
        });
    }
}
