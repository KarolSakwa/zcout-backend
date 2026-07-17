<?php

namespace Tests\Unit\Simulation\Decision;

use App\Simulation\Decision\DuelDecisionPolicy;
use App\Simulation\Decision\SimulationLabDecisionSeed;
use PHPUnit\Framework\TestCase;

final class DuelDecisionPolicyTest extends TestCase
{
    private DuelDecisionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new DuelDecisionPolicy();
    }

    public function test_simulation_lab_seed_format_preserves_legacy_behavior(): void
    {
        $seed = SimulationLabDecisionSeed::build(
            runId: 2,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
        );

        $this->assertSame('2|1|u1|expert|10|20|passing', $seed);

        $result = $this->policy->decide(
            decisionSeed: $seed,
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $result->type);
    }

    public function test_expert_skips_on_small_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 2,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $result->type);
        $this->assertNull($result->winnerPlayerId);
        $this->assertNull($result->truthDiff);
    }

    public function test_casual_skips_on_small_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 5,
            currentStep: 1,
            userId: 'u1',
            userType: 'casual',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $result->type);
    }

    public function test_biased_skips_on_small_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 12,
            currentStep: 1,
            userId: 'u1',
            userType: 'biased',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $result->type);
    }

    public function test_noisy_skips_on_small_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 1,
            currentStep: 1,
            userId: 'u1',
            userType: 'noisy',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $result->type);
    }

    public function test_expert_votes_for_player_a_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 1,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(10, $result->winnerPlayerId);
        $this->assertSame(20.0, $result->truthDiff);
    }

    public function test_expert_votes_for_player_b_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 71,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(20, $result->winnerPlayerId);
        $this->assertSame(20.0, $result->truthDiff);
    }

    public function test_casual_votes_for_player_b_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 8,
            currentStep: 1,
            userId: 'u1',
            userType: 'casual',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(20, $result->winnerPlayerId);
    }

    public function test_noisy_votes_for_player_b_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 3,
            currentStep: 1,
            userId: 'u1',
            userType: 'noisy',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(20, $result->winnerPlayerId);
    }

    public function test_biased_applies_bias_override_for_small_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 2,
            currentStep: 1,
            userId: 'u1',
            userType: 'biased',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 72.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(10, $result->winnerPlayerId);
        $this->assertSame(2.0, $result->truthDiff);
    }

    public function test_biased_can_vote_for_player_b_on_large_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 42,
            currentStep: 1,
            userId: 'u1',
            userType: 'biased',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(20, $result->winnerPlayerId);
    }

    public function test_casual_skips_on_very_small_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 7,
            currentStep: 3,
            userId: 'u5',
            userType: 'casual',
            playerAId: 100,
            playerBId: 200,
            attributeKey: 'dribbling',
            truthRatingA: 55.0,
            truthRatingB: 54.0,
        );

        $this->assertSame('skip', $result->type);
    }

    public function test_casual_votes_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 42,
            currentStep: 1,
            userId: 'u1',
            userType: 'casual',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(10, $result->winnerPlayerId);
    }

    public function test_noisy_votes_for_player_a_on_large_truth_rating_difference(): void
    {
        $result = $this->decideForSimulationLab(
            runId: 42,
            currentStep: 1,
            userId: 'u1',
            userType: 'noisy',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 90.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('vote', $result->type);
        $this->assertSame(10, $result->winnerPlayerId);
    }

    public function test_identical_inputs_produce_identical_results(): void
    {
        $first = $this->decideForSimulationLab(
            runId: 7,
            currentStep: 3,
            userId: 'u5',
            userType: 'casual',
            playerAId: 100,
            playerBId: 200,
            attributeKey: 'dribbling',
            truthRatingA: 55.0,
            truthRatingB: 54.0,
        );
        $second = $this->decideForSimulationLab(
            runId: 7,
            currentStep: 3,
            userId: 'u5',
            userType: 'casual',
            playerAId: 100,
            playerBId: 200,
            attributeKey: 'dribbling',
            truthRatingA: 55.0,
            truthRatingB: 54.0,
        );

        $this->assertSame($first->type, $second->type);
        $this->assertSame($first->winnerPlayerId, $second->winnerPlayerId);
        $this->assertSame($first->truthDiff, $second->truthDiff);
    }

    public function test_changing_current_step_changes_the_result(): void
    {
        $stepThree = $this->decideForSimulationLab(
            runId: 7,
            currentStep: 3,
            userId: 'u5',
            userType: 'casual',
            playerAId: 100,
            playerBId: 200,
            attributeKey: 'dribbling',
            truthRatingA: 55.0,
            truthRatingB: 54.0,
        );
        $stepFour = $this->decideForSimulationLab(
            runId: 7,
            currentStep: 4,
            userId: 'u5',
            userType: 'casual',
            playerAId: 100,
            playerBId: 200,
            attributeKey: 'dribbling',
            truthRatingA: 55.0,
            truthRatingB: 54.0,
        );

        $this->assertSame('skip', $stepThree->type);
        $this->assertSame('vote', $stepFour->type);
        $this->assertSame(100, $stepFour->winnerPlayerId);
    }

    public function test_changing_run_id_changes_the_result(): void
    {
        $runTwo = $this->decideForSimulationLab(
            runId: 2,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );
        $runThree = $this->decideForSimulationLab(
            runId: 3,
            currentStep: 1,
            userId: 'u1',
            userType: 'expert',
            playerAId: 10,
            playerBId: 20,
            attributeKey: 'passing',
            truthRatingA: 75.0,
            truthRatingB: 70.0,
        );

        $this->assertSame('skip', $runTwo->type);
        $this->assertSame('vote', $runThree->type);
    }

    private function decideForSimulationLab(
        int $runId,
        int $currentStep,
        string $userId,
        string $userType,
        int $playerAId,
        int $playerBId,
        string $attributeKey,
        float $truthRatingA,
        float $truthRatingB,
    ) {
        return $this->policy->decide(
            decisionSeed: SimulationLabDecisionSeed::build(
                runId: $runId,
                currentStep: $currentStep,
                userId: $userId,
                userType: $userType,
                playerAId: $playerAId,
                playerBId: $playerBId,
                attributeKey: $attributeKey,
            ),
            userType: $userType,
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: $attributeKey,
            truthRatingA: $truthRatingA,
            truthRatingB: $truthRatingB,
        );
    }
}
