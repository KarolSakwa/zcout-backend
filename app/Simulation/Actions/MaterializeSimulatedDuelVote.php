<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\SimulatedDuelVote;
use App\Simulation\SimulationContext;
use Illuminate\Support\Facades\DB;

final class MaterializeSimulatedDuelVote
{
    public function handle(SimulatedDuelVote $vote, SimulationContext $context): void
    {
        DB::table('simulation_run_events')->insert([
            'simulation_run_id' => $context->runId,
            'source' => 'duel',
            'event_type' => $vote->decisionType,
            'simulated_user_id' => $vote->simulatedUserId,
            'is_logged' => $vote->isLogged,
            'payload' => json_encode([
                'player_a_id' => $vote->playerAId,
                'player_b_id' => $vote->playerBId,
                'attribute_key' => $vote->attributeKey,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
