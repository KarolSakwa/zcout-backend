<?php

namespace Database\Factories;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticPoolIdentity;
use App\Simulation\Synthetic\SyntheticUserProfileDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::USER,
            'influence_profile' => InfluenceProfile::USER_DEFAULT,
            'is_synthetic' => false,
            'synthetic_pool_key' => null,
            'synthetic_pool_index' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Mark the user as synthetic and create a linked profile with defaults.
     */
    public function synthetic(?string $decisionProfile = null): static
    {
        $profile = $decisionProfile ?? SyntheticUserProfileDefaults::DECISION_PROFILE;

        return $this
            ->state(fn (): array => [
                'is_synthetic' => true,
            ])
            ->afterCreating(function (User $user) use ($profile): void {
                $user->syntheticProfile()->create(array_merge(
                    SyntheticUserProfileDefaults::attributes(),
                    [
                        'decision_profile' => $profile,
                    ],
                ));
            });
    }

    /**
     * Create a managed synthetic pool member with deterministic identity.
     */
    public function syntheticPoolMember(
        string $pool = 'default',
        int $index = 1,
        ?string $profile = null,
    ): static {
        $decisionProfile = $profile ?? SyntheticDecisionProfiles::CASUAL;
        $identity = app(SyntheticPoolIdentity::class);

        return $this
            ->state(fn (): array => [
                'name' => $identity->displayName($pool, $index),
                'email' => $identity->email($pool, $index),
                'is_synthetic' => true,
                'synthetic_pool_key' => $pool,
                'synthetic_pool_index' => $index,
                'role' => UserRole::USER,
                'influence_profile' => InfluenceProfile::USER_DEFAULT,
            ])
            ->afterCreating(function (User $user) use ($decisionProfile): void {
                $user->syntheticProfile()->create(array_merge(
                    SyntheticUserProfileDefaults::attributes(),
                    [
                        'decision_profile' => $decisionProfile,
                        'is_enabled' => true,
                    ],
                ));
            });
    }
}
