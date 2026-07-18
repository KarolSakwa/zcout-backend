<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Models\SyntheticUserProfile;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticProfilePresets;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyntheticProfileFactoryPresetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_creates_full_casual_preset(): void
    {
        $profile = SyntheticUserProfile::factory()->create();

        $this->assertProfileMatchesPreset($profile, SyntheticDecisionProfiles::CASUAL);
    }

    public function test_expert_state_creates_full_expert_preset(): void
    {
        $profile = SyntheticUserProfile::factory()->expert()->create();

        $this->assertProfileMatchesPreset($profile, SyntheticDecisionProfiles::EXPERT);
    }

    public function test_noisy_state_creates_full_noisy_preset(): void
    {
        $profile = SyntheticUserProfile::factory()->noisy()->create();

        $this->assertProfileMatchesPreset($profile, SyntheticDecisionProfiles::NOISY);
    }

    public function test_disabled_keeps_preset_values_and_sets_is_enabled_false(): void
    {
        $profile = SyntheticUserProfile::factory()->expert()->disabled()->create();
        $expected = SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT);
        $expected['is_enabled'] = false;

        $this->assertProfileAttributes($profile, $expected);
    }

    public function test_user_factory_synthetic_creates_casual_by_default(): void
    {
        $user = User::factory()->synthetic()->create();

        $this->assertProfileMatchesPreset($user->syntheticProfile, SyntheticDecisionProfiles::CASUAL);
    }

    public function test_user_factory_synthetic_expert_and_noisy(): void
    {
        $expert = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $noisy = User::factory()->synthetic(SyntheticDecisionProfiles::NOISY)->create();

        $this->assertProfileMatchesPreset($expert->syntheticProfile, SyntheticDecisionProfiles::EXPERT);
        $this->assertProfileMatchesPreset($noisy->syntheticProfile, SyntheticDecisionProfiles::NOISY);
    }

    public function test_user_factory_synthetic_biased_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid synthetic decision_profile');

        User::factory()->synthetic('biased')->create();
    }

    public function test_regular_user_factory_has_no_profile(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_synthetic);
        $this->assertNull($user->syntheticProfile);
    }

    private function assertProfileMatchesPreset(SyntheticUserProfile $profile, string $archetype): void
    {
        $this->assertProfileAttributes($profile, SyntheticProfilePresets::for($archetype));
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertProfileAttributes(SyntheticUserProfile $profile, array $expected): void
    {
        $this->assertSame($expected['decision_profile'], $profile->decision_profile);
        $this->assertSame($expected['sessions_per_day_min'], $profile->sessions_per_day_min);
        $this->assertSame($expected['sessions_per_day_max'], $profile->sessions_per_day_max);
        $this->assertSame($expected['actions_per_session_min'], $profile->actions_per_session_min);
        $this->assertSame($expected['actions_per_session_max'], $profile->actions_per_session_max);
        $this->assertSame($expected['delay_seconds_min'], $profile->delay_seconds_min);
        $this->assertSame($expected['delay_seconds_max'], $profile->delay_seconds_max);
        $this->assertEqualsWithDelta($expected['skip_probability'], $profile->skip_probability, 1e-9);
        $this->assertEqualsWithDelta($expected['decision_accuracy'], $profile->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta($expected['noise_level'], $profile->noise_level, 1e-9);
        $this->assertSame($expected['is_enabled'], $profile->is_enabled);
    }
}
