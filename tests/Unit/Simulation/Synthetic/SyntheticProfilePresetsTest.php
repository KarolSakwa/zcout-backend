<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticProfilePresets;
use App\Simulation\Synthetic\SyntheticUserProfileDefaults;
use App\Simulation\Synthetic\ValidateSyntheticUserProfile;
use DomainException;
use Tests\TestCase;

final class SyntheticProfilePresetsTest extends TestCase
{
    public function test_casual_preset_has_expected_literal_values(): void
    {
        $this->assertSame([
            'decision_profile' => 'casual',
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 2,
            'actions_per_session_min' => 3,
            'actions_per_session_max' => 8,
            'delay_seconds_min' => 6,
            'delay_seconds_max' => 20,
            'skip_probability' => 0.12,
            'decision_accuracy' => 0.72,
            'noise_level' => 0.15,
            'is_enabled' => true,
        ], SyntheticProfilePresets::for(SyntheticDecisionProfiles::CASUAL));
    }

    public function test_expert_preset_has_expected_literal_values(): void
    {
        $this->assertSame([
            'decision_profile' => 'expert',
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 2,
            'actions_per_session_min' => 4,
            'actions_per_session_max' => 9,
            'delay_seconds_min' => 7,
            'delay_seconds_max' => 20,
            'skip_probability' => 0.08,
            'decision_accuracy' => 0.90,
            'noise_level' => 0.05,
            'is_enabled' => true,
        ], SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT));
    }

    public function test_noisy_preset_has_expected_literal_values(): void
    {
        $this->assertSame([
            'decision_profile' => 'noisy',
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 3,
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 7,
            'delay_seconds_min' => 3,
            'delay_seconds_max' => 14,
            'skip_probability' => 0.18,
            'decision_accuracy' => 0.58,
            'noise_level' => 0.45,
            'is_enabled' => true,
        ], SyntheticProfilePresets::for(SyntheticDecisionProfiles::NOISY));
    }

    public function test_each_preset_passes_validation_and_matches_key(): void
    {
        $validator = app(ValidateSyntheticUserProfile::class);

        foreach (SyntheticDecisionProfiles::ALLOWED as $profile) {
            $attributes = SyntheticProfilePresets::for($profile);
            $validator->validate($attributes);
            $this->assertSame($profile, $attributes['decision_profile']);
            $this->assertTrue($attributes['is_enabled']);
        }
    }

    public function test_biased_and_unknown_profiles_are_rejected(): void
    {
        foreach (['biased', 'unknown', ''] as $profile) {
            try {
                SyntheticProfilePresets::for($profile);
                $this->fail('Expected DomainException for profile: '.$profile);
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Invalid synthetic decision_profile', $exception->getMessage());
            }
        }
    }

    public function test_defaults_delegate_to_casual_preset(): void
    {
        $this->assertSame(
            SyntheticProfilePresets::for(SyntheticDecisionProfiles::CASUAL),
            SyntheticUserProfileDefaults::attributes(),
        );
        $this->assertSame(SyntheticDecisionProfiles::CASUAL, SyntheticUserProfileDefaults::DECISION_PROFILE);
    }

    public function test_presets_do_not_share_mutable_state(): void
    {
        $first = SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT);
        $first['skip_probability'] = 0.99;

        $second = SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT);

        $this->assertSame(0.08, $second['skip_probability']);
        $this->assertNotSame(0.99, $second['skip_probability']);
    }
}
