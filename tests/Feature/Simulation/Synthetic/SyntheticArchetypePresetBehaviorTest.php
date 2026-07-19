<?php

namespace Tests\Feature\Simulation\Synthetic;

use App\Models\User;
use App\Simulation\Decision\SyntheticDuelDecisionPolicy;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticProfilePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyntheticArchetypePresetBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_archetypes_drive_different_deterministic_decisions(): void
    {
        $expertUser = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $noisyUser = User::factory()->synthetic(SyntheticDecisionProfiles::NOISY)->create();
        $casualUser = User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();

        $expert = $expertUser->syntheticProfile;
        $noisy = $noisyUser->syntheticProfile;
        $casual = $casualUser->syntheticProfile;

        $this->assertNotEquals($expert->skip_probability, $noisy->skip_probability);
        $this->assertNotEquals($expert->decision_accuracy, $noisy->decision_accuracy);
        $this->assertNotEquals($expert->noise_level, $noisy->noise_level);

        $casualPreset = SyntheticProfilePresets::for(SyntheticDecisionProfiles::CASUAL);
        $this->assertEqualsWithDelta($casualPreset['skip_probability'], $casual->skip_probability, 1e-9);
        $this->assertEqualsWithDelta($casualPreset['decision_accuracy'], $casual->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta($casualPreset['noise_level'], $casual->noise_level, 1e-9);

        $seed = $this->findSeedWithSkipRollBetween(
            $expert->skip_probability,
            $noisy->skip_probability,
        );

        $policy = new SyntheticDuelDecisionPolicy();

        $expertDecision = $policy->decide(
            decisionSeed: $seed,
            playerAId: 10,
            playerBId: 20,
            ratingA: 70.0,
            ratingB: 40.0,
            skipProbability: (float) $expert->skip_probability,
            decisionAccuracy: (float) $expert->decision_accuracy,
            noiseLevel: (float) $expert->noise_level,
        );

        $noisyDecision = $policy->decide(
            decisionSeed: $seed,
            playerAId: 10,
            playerBId: 20,
            ratingA: 70.0,
            ratingB: 40.0,
            skipProbability: (float) $noisy->skip_probability,
            decisionAccuracy: (float) $noisy->decision_accuracy,
            noiseLevel: (float) $noisy->noise_level,
        );

        $this->assertSame('vote', $expertDecision->type);
        $this->assertSame('skip', $noisyDecision->type);
        $this->assertSame(10, $expertDecision->winnerPlayerId);

        // Same decision_profile label cannot explain the divergence: swap labels, keep numbers.
        $expert->update(['decision_profile' => SyntheticDecisionProfiles::NOISY]);
        $noisy->update(['decision_profile' => SyntheticDecisionProfiles::EXPERT]);

        $expertAfterLabelSwap = $policy->decide(
            decisionSeed: $seed,
            playerAId: 10,
            playerBId: 20,
            ratingA: 70.0,
            ratingB: 40.0,
            skipProbability: (float) $expert->fresh()->skip_probability,
            decisionAccuracy: (float) $expert->fresh()->decision_accuracy,
            noiseLevel: (float) $expert->fresh()->noise_level,
        );
        $noisyAfterLabelSwap = $policy->decide(
            decisionSeed: $seed,
            playerAId: 10,
            playerBId: 20,
            ratingA: 70.0,
            ratingB: 40.0,
            skipProbability: (float) $noisy->fresh()->skip_probability,
            decisionAccuracy: (float) $noisy->fresh()->decision_accuracy,
            noiseLevel: (float) $noisy->fresh()->noise_level,
        );

        $this->assertSame($expertDecision->type, $expertAfterLabelSwap->type);
        $this->assertSame($noisyDecision->type, $noisyAfterLabelSwap->type);
    }

    private function findSeedWithSkipRollBetween(float $lowerExclusiveBound, float $upperExclusiveBound): string
    {
        for ($i = 0; $i < 20_000; $i++) {
            $seed = 'archetype-skip-diff-'.$i;
            $digest = hash('sha256', $seed.'|synthetic-skip');
            $roll = (hexdec(substr($digest, 0, 8)) % 1_000_000) / 1_000_000;

            if ($roll >= $lowerExclusiveBound && $roll < $upperExclusiveBound) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic seed with skip roll between archetype thresholds.');
    }
}
