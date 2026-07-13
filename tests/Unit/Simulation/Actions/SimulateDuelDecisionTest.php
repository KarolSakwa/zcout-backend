<?php

namespace Tests\Unit\Simulation\Actions;

use App\Simulation\Decision\DuelDecisionPolicy;
use App\Models\SimulationRun;
use App\Models\SimulationRunTruthRating;
use App\Simulation\Actions\SimulateDuelDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SimulateDuelDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_when_truth_rating_is_missing(): void
    {
        [$runId, $playerAId, $playerBId] = $this->seedTruthScenario(
            truthRatingA: null,
            truthRatingB: 70.0,
        );

        $decision = $this->makeAction()->handle(
            $this->opportunity($playerAId, $playerBId),
            new SimulatedUser(id: 'u1', type: 'expert', isLogged: true, appUserId: 1),
            $this->context($runId),
        );

        $this->assertNotNull($decision);
        $this->assertSame('duel', $decision->source);
        $this->assertSame('skip', $decision->type);
        $this->assertSame([], $decision->payload);
    }

    public function test_it_delegates_vote_decision_to_decision_policy(): void
    {
        [$runId, $playerAId, $playerBId] = $this->seedTruthScenario(
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $decision = $this->makeAction()->handle(
            $this->opportunity($playerAId, $playerBId),
            new SimulatedUser(id: 'u1', type: 'expert', isLogged: true, appUserId: 1),
            $this->context($runId, currentStep: 1),
        );

        $expected = (new DuelDecisionPolicy())->calculate(
            runId: $runId,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertNotNull($decision);
        $this->assertSame('vote', $decision->type);
        $this->assertSame($expected->winnerPlayerId, $decision->payload['winner_player_id']);
        $this->assertSame($expected->truthDiff, $decision->payload['truth_diff']);
    }

    public function test_it_delegates_skip_decision_to_decision_policy(): void
    {
        [$runId, $playerAId, $playerBId] = $this->seedTruthScenario(
            truthRatingA: 75.0,
            truthRatingB: 70.0,
            runId: 2,
        );

        $decision = $this->makeAction()->handle(
            $this->opportunity($playerAId, $playerBId),
            new SimulatedUser(id: 'u1', type: 'expert', isLogged: true, appUserId: 1),
            $this->context($runId, currentStep: 1),
        );

        $expected = (new DuelDecisionPolicy())->calculate(
            runId: $runId,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertNotNull($decision);
        $this->assertSame($expected->type, $decision->type);

        if ($expected->type === 'skip') {
            $this->assertSame([], $decision->payload);
        } else {
            $this->assertSame($expected->winnerPlayerId, $decision->payload['winner_player_id']);
            $this->assertSame($expected->truthDiff, $decision->payload['truth_diff']);
        }
    }

    private function makeAction(): SimulateDuelDecision
    {
        return new SimulateDuelDecision();
    }

    private function opportunity(int $playerAId, int $playerBId): InteractionOpportunity
    {
        return new InteractionOpportunity(
            source: 'duel',
            type: 'pair',
            payload: [
                'duel_id' => 99,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'attribute' => 'passing',
            ],
        );
    }

    private function context(int $runId, int $currentStep = 1): SimulationContext
    {
        return new SimulationContext(
            mode: 'report',
            runId: $runId,
            now: new \DateTimeImmutable('2026-01-01 00:00:00'),
            config: ['seed' => 12345, 'steps_per_user' => 1],
            currentStep: $currentStep,
        );
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function seedTruthScenario(?float $truthRatingA, ?float $truthRatingB, ?int $runId = null): array
    {
        DB::table('attributes')->insert([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'TECH',
            'order' => 1,
            'scope' => 'both',
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'id' => 10,
            'name' => 'Player A',
            'slug' => 'player-a',
            'club' => 'Club A',
            'number' => 1,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'id' => 20,
            'name' => 'Player B',
            'slug' => 'player-b',
            'club' => 'Club B',
            'number' => 2,
        ]);

        $run = SimulationRun::query()->create([
            'id' => $runId,
            'mode' => 'report',
            'status' => 'running',
            'config' => ['seed' => 12345],
            'started_at' => now(),
        ]);

        if ($truthRatingA !== null) {
            SimulationRunTruthRating::query()->create([
                'simulation_run_id' => $run->id,
                'player_id' => $playerAId,
                'attribute_key' => 'passing',
                'truth_rating' => $truthRatingA,
                'source_label' => 'test',
            ]);
        }

        if ($truthRatingB !== null) {
            SimulationRunTruthRating::query()->create([
                'simulation_run_id' => $run->id,
                'player_id' => $playerBId,
                'attribute_key' => 'passing',
                'truth_rating' => $truthRatingB,
                'source_label' => 'test',
            ]);
        }

        return [$run->id, $playerAId, $playerBId];
    }
}
