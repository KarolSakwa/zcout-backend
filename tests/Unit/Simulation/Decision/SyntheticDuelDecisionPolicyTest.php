<?php

namespace Tests\Unit\Simulation\Decision;

use App\Simulation\Decision\SyntheticDuelDecisionPolicy;
use DomainException;
use Tests\TestCase;

final class SyntheticDuelDecisionPolicyTest extends TestCase
{
    private SyntheticDuelDecisionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SyntheticDuelDecisionPolicy();
    }

    public function test_skip_probability_one_always_skips(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $result = $this->policy->decide(
                decisionSeed: 'seed-'.$i,
                playerAId: 1,
                playerBId: 2,
                ratingA: 60,
                ratingB: 40,
                skipProbability: 1.0,
                decisionAccuracy: 1.0,
                noiseLevel: 0.0,
            );

            $this->assertSame('skip', $result->type);
            $this->assertNull($result->winnerPlayerId);
        }
    }

    public function test_skip_probability_zero_never_skips(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $result = $this->policy->decide(
                decisionSeed: 'seed-'.$i,
                playerAId: 1,
                playerBId: 2,
                ratingA: 60,
                ratingB: 40,
                skipProbability: 0.0,
                decisionAccuracy: 1.0,
                noiseLevel: 0.0,
            );

            $this->assertSame('vote', $result->type);
        }
    }

    public function test_intermediate_skip_is_deterministic_per_seed(): void
    {
        $seed = 'fixed-skip-seed';
        $a = $this->policy->decide($seed, 1, 2, 55, 45, 0.5, 1.0, 0.0);
        $b = $this->policy->decide($seed, 1, 2, 55, 45, 0.5, 1.0, 0.0);

        $this->assertSame($a->type, $b->type);
        $this->assertSame($a->winnerPlayerId, $b->winnerPlayerId);

        $types = [];
        for ($i = 1; $i <= 40; $i++) {
            $types[$this->policy->decide('skip-var-'.$i, 1, 2, 55, 45, 0.5, 1.0, 0.0)->type] = true;
        }

        $this->assertArrayHasKey('skip', $types);
        $this->assertArrayHasKey('vote', $types);
    }

    public function test_accuracy_one_noise_zero_always_picks_higher_rating(): void
    {
        $resultA = $this->policy->decide('acc-a', 10, 20, 70, 40, 0.0, 1.0, 0.0);
        $resultB = $this->policy->decide('acc-b', 10, 20, 40, 70, 0.0, 1.0, 0.0);

        $this->assertSame('vote', $resultA->type);
        $this->assertSame(10, $resultA->winnerPlayerId);
        $this->assertSame(20, $resultB->winnerPlayerId);
        $this->assertSame(30.0, $resultA->truthDiff);
        $this->assertSame(-30.0, $resultB->truthDiff);
    }

    public function test_accuracy_zero_noise_zero_always_picks_lower_rating(): void
    {
        $resultA = $this->policy->decide('wrong-a', 10, 20, 70, 40, 0.0, 0.0, 0.0);
        $resultB = $this->policy->decide('wrong-b', 10, 20, 40, 70, 0.0, 0.0, 0.0);

        $this->assertSame(20, $resultA->winnerPlayerId);
        $this->assertSame(10, $resultB->winnerPlayerId);
    }

    public function test_accuracy_half_follows_correctness_roll(): void
    {
        $seed = 'half-acc-seed';
        $first = $this->policy->decide($seed, 1, 2, 80, 50, 0.0, 0.5, 0.0);
        $second = $this->policy->decide($seed, 1, 2, 80, 50, 0.0, 0.5, 0.0);

        $this->assertSame($first->winnerPlayerId, $second->winnerPlayerId);
        $this->assertContains($first->winnerPlayerId, [1, 2]);
    }

    public function test_noise_formula_and_bounds(): void
    {
        $this->assertSame(1.0, $this->effectiveAccuracy(1.0, 0.0));
        $this->assertSame(0.5, $this->effectiveAccuracy(0.5, 0.0));
        $this->assertSame(0.5, $this->effectiveAccuracy(0.8, 1.0));
        $this->assertSame(0.5, $this->effectiveAccuracy(1.0, 1.0));
        $this->assertEqualsWithDelta(0.65, $this->effectiveAccuracy(0.8, 0.5), 1e-9);

        $base = 0.9;
        $withNoise = $this->effectiveAccuracy($base, 0.4);
        $this->assertTrue(abs($withNoise - 0.5) <= abs($base - 0.5) + 1e-12);
    }

    public function test_tie_uses_tie_roll_and_ignores_accuracy(): void
    {
        $seed = 'tie-seed-1';
        $a = $this->policy->decide($seed, 11, 22, 50, 50, 0.0, 1.0, 0.0);
        $b = $this->policy->decide($seed, 11, 22, 50, 50, 0.0, 0.0, 1.0);

        $this->assertSame('vote', $a->type);
        $this->assertSame($a->winnerPlayerId, $b->winnerPlayerId);
        $this->assertSame(0.0, $a->truthDiff);

        $winners = [];
        for ($i = 1; $i <= 40; $i++) {
            $winners[$this->policy->decide('tie-var-'.$i, 11, 22, 50, 50, 0.0, 1.0, 0.0)->winnerPlayerId] = true;
        }

        $this->assertArrayHasKey(11, $winners);
        $this->assertArrayHasKey(22, $winners);
    }

    public function test_validation_rejects_out_of_range_probabilities(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('skipProbability');

        $this->policy->decide('x', 1, 2, 1, 2, -0.01, 0.5, 0.5);
    }

    public function test_validation_rejects_probability_above_one(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('decisionAccuracy');

        $this->policy->decide('x', 1, 2, 1, 2, 0.0, 1.01, 0.0);
    }

    public function test_validation_allows_zero_and_one_bounds(): void
    {
        $result = $this->policy->decide('bounds', 1, 2, 10, 20, 0.0, 1.0, 0.0);

        $this->assertSame('vote', $result->type);
        $this->assertSame(2, $result->winnerPlayerId);
    }

    public function test_vote_winner_belongs_to_duel(): void
    {
        $result = $this->policy->decide('winner-check', 100, 200, 55, 45, 0.0, 1.0, 0.0);

        $this->assertContains($result->winnerPlayerId, [100, 200]);
    }

    private function effectiveAccuracy(float $decisionAccuracy, float $noiseLevel): float
    {
        $value = 0.5 + ($decisionAccuracy - 0.5) * (1.0 - $noiseLevel);

        return max(0.0, min(1.0, $value));
    }
}
