<?php

namespace Tests\Unit\Simulation\Decision;

use App\Simulation\Decision\DuelDecisionPolicy;
use PHPUnit\Framework\TestCase;

final class DuelDecisionPolicyTest extends TestCase
{
    private DuelDecisionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new DuelDecisionPolicy();
    }

    public function test_expert_skips_on_small_truth_rating_difference(): void
    {
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $result = $this->policy->calculate(
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
        $inputs = [
            'runId' => 7,
            'currentStep' => 3,
            'userId' => 'u5',
            'userType' => 'casual',
            'playerAId' => 100,
            'playerBId' => 200,
            'attributeKey' => 'dribbling',
            'truthRatingA' => 55.0,
            'truthRatingB' => 54.0,
        ];

        $first = $this->policy->calculate(...$inputs);
        $second = $this->policy->calculate(...$inputs);

        $this->assertSame($first->type, $second->type);
        $this->assertSame($first->winnerPlayerId, $second->winnerPlayerId);
        $this->assertSame($first->truthDiff, $second->truthDiff);
    }

    public function test_changing_current_step_changes_the_result(): void
    {
        $base = [
            'runId' => 7,
            'userId' => 'u5',
            'userType' => 'casual',
            'playerAId' => 100,
            'playerBId' => 200,
            'attributeKey' => 'dribbling',
            'truthRatingA' => 55.0,
            'truthRatingB' => 54.0,
        ];

        $stepThree = $this->policy->calculate(...$base, currentStep: 3);
        $stepFour = $this->policy->calculate(...$base, currentStep: 4);

        $this->assertSame('skip', $stepThree->type);
        $this->assertSame('vote', $stepFour->type);
        $this->assertSame(100, $stepFour->winnerPlayerId);
    }

    public function test_changing_run_id_changes_the_result(): void
    {
        $base = [
            'currentStep' => 1,
            'userId' => 'u1',
            'userType' => 'expert',
            'playerAId' => 10,
            'playerBId' => 20,
            'attributeKey' => 'passing',
            'truthRatingA' => 75.0,
            'truthRatingB' => 70.0,
        ];

        $runTwo = $this->policy->calculate(...$base, runId: 2);
        $runThree = $this->policy->calculate(...$base, runId: 3);

        $this->assertSame('skip', $runTwo->type);
        $this->assertSame('vote', $runThree->type);
    }
}
