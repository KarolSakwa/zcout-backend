<?php

namespace Database\Factories;

use App\Models\SyntheticUserProfile;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticUserProfileDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyntheticUserProfile>
 */
class SyntheticUserProfileFactory extends Factory
{
    protected $model = SyntheticUserProfile::class;

    public function definition(): array
    {
        return array_merge(
            SyntheticUserProfileDefaults::attributes(),
            [
                'user_id' => User::factory()->state([
                    'is_synthetic' => true,
                ]),
            ],
        );
    }

    public function expert(): static
    {
        return $this->state(fn (): array => [
            'decision_profile' => SyntheticDecisionProfiles::EXPERT,
        ]);
    }

    public function casual(): static
    {
        return $this->state(fn (): array => [
            'decision_profile' => SyntheticDecisionProfiles::CASUAL,
        ]);
    }

    public function noisy(): static
    {
        return $this->state(fn (): array => [
            'decision_profile' => SyntheticDecisionProfiles::NOISY,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'is_enabled' => false,
        ]);
    }
}
