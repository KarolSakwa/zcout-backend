<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Models\SyntheticUserProfile;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticProfilePresets;
use App\Simulation\Synthetic\SyntheticUserProfileDefaults;
use App\Simulation\Synthetic\ValidateSyntheticUserProfile;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyntheticUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_is_not_synthetic_and_has_no_profile(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_synthetic);
        $this->assertNull($user->syntheticProfile);
    }

    public function test_synthetic_factory_creates_user_with_default_profile(): void
    {
        $user = User::factory()->synthetic()->create();

        $this->assertTrue($user->is_synthetic);
        $this->assertNotNull($user->syntheticProfile);
        $this->assertSame(1, $user->syntheticProfile()->count());

        $profile = $user->syntheticProfile;
        $expected = SyntheticProfilePresets::for(SyntheticDecisionProfiles::CASUAL);
        $this->assertSame($expected['decision_profile'], $profile->decision_profile);
        $this->assertSame($expected['sessions_per_day_min'], $profile->sessions_per_day_min);
        $this->assertSame($expected['sessions_per_day_max'], $profile->sessions_per_day_max);
        $this->assertSame($expected['actions_per_session_min'], $profile->actions_per_session_min);
        $this->assertSame($expected['actions_per_session_max'], $profile->actions_per_session_max);
        $this->assertSame($expected['delay_seconds_min'], $profile->delay_seconds_min);
        $this->assertSame($expected['delay_seconds_max'], $profile->delay_seconds_max);
        $this->assertEqualsWithDelta($expected['skip_probability'], $profile->skip_probability, 0.0001);
        $this->assertEqualsWithDelta($expected['decision_accuracy'], $profile->decision_accuracy, 0.0001);
        $this->assertEqualsWithDelta($expected['noise_level'], $profile->noise_level, 0.0001);
        $this->assertTrue($profile->is_enabled);
        $this->assertIsBool($profile->is_enabled);
        $this->assertIsInt($profile->sessions_per_day_min);
        $this->assertIsFloat($profile->skip_probability);
    }

    public function test_synthetic_factory_can_override_decision_profile(): void
    {
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $expected = SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT);

        $this->assertSame($expected['decision_profile'], $user->syntheticProfile->decision_profile);
        $this->assertEqualsWithDelta($expected['skip_probability'], $user->syntheticProfile->skip_probability, 1e-9);
        $this->assertEqualsWithDelta($expected['decision_accuracy'], $user->syntheticProfile->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta($expected['noise_level'], $user->syntheticProfile->noise_level, 1e-9);
    }

    public function test_profile_is_deleted_when_user_is_deleted(): void
    {
        $user = User::factory()->synthetic()->create();
        $profileId = $user->syntheticProfile->id;

        $user->delete();

        $this->assertDatabaseMissing('synthetic_user_profiles', [
            'id' => $profileId,
        ]);
    }

    public function test_biased_decision_profile_is_rejected(): void
    {
        $user = User::factory()->create(['is_synthetic' => true]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid synthetic decision_profile');

        $user->syntheticProfile()->create(array_merge(
            SyntheticUserProfileDefaults::attributes(),
            ['decision_profile' => 'biased'],
        ));
    }

    public function test_min_max_constraints_are_validated(): void
    {
        $validator = app(ValidateSyntheticUserProfile::class);
        $base = SyntheticUserProfileDefaults::attributes();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sessions_per_day_max must be greater than or equal to sessions_per_day_min');

        $validator->validate(array_merge($base, [
            'sessions_per_day_min' => 3,
            'sessions_per_day_max' => 1,
        ]));
    }

    public function test_actions_per_session_min_must_be_positive(): void
    {
        $validator = app(ValidateSyntheticUserProfile::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('actions_per_session_min must be an integer greater than 0');

        $validator->validate(array_merge(SyntheticUserProfileDefaults::attributes(), [
            'actions_per_session_min' => 0,
        ]));
    }

    public function test_probability_fields_reject_out_of_range_values(): void
    {
        $validator = app(ValidateSyntheticUserProfile::class);

        try {
            $validator->validate(array_merge(SyntheticUserProfileDefaults::attributes(), [
                'skip_probability' => -0.01,
            ]));
            $this->fail('Expected DomainException for skip_probability < 0');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('skip_probability', $exception->getMessage());
        }

        try {
            $validator->validate(array_merge(SyntheticUserProfileDefaults::attributes(), [
                'decision_accuracy' => 1.01,
            ]));
            $this->fail('Expected DomainException for decision_accuracy > 1');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('decision_accuracy', $exception->getMessage());
        }

        try {
            $validator->validate(array_merge(SyntheticUserProfileDefaults::attributes(), [
                'noise_level' => 2,
            ]));
            $this->fail('Expected DomainException for noise_level > 1');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('noise_level', $exception->getMessage());
        }
    }

    public function test_allowed_profiles_list_excludes_biased(): void
    {
        $this->assertTrue(SyntheticDecisionProfiles::isAllowed('expert'));
        $this->assertTrue(SyntheticDecisionProfiles::isAllowed('casual'));
        $this->assertTrue(SyntheticDecisionProfiles::isAllowed('noisy'));
        $this->assertFalse(SyntheticDecisionProfiles::isAllowed('biased'));
        $this->assertSame(['expert', 'casual', 'noisy'], SyntheticDecisionProfiles::ALLOWED);
    }

    public function test_profile_factory_expert_state(): void
    {
        $profile = SyntheticUserProfile::factory()->expert()->create();
        $expected = SyntheticProfilePresets::for(SyntheticDecisionProfiles::EXPERT);

        $this->assertSame($expected['decision_profile'], $profile->decision_profile);
        $this->assertEqualsWithDelta($expected['skip_probability'], $profile->skip_probability, 1e-9);
        $this->assertEqualsWithDelta($expected['decision_accuracy'], $profile->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta($expected['noise_level'], $profile->noise_level, 1e-9);
        $this->assertTrue($profile->user->is_synthetic);
    }

    public function test_cannot_create_profile_for_regular_user(): void
    {
        $user = User::factory()->create();

        try {
            SyntheticUserProfile::factory()->for($user)->create();
            $this->fail('Expected DomainException when attaching profile to a regular user.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Synthetic user profile can only belong to a user with is_synthetic=true.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('synthetic_user_profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_synthetic_user_has_exactly_one_profile(): void
    {
        $user = User::factory()->synthetic()->create();

        $this->assertTrue($user->is_synthetic);
        $this->assertSame(1, $user->syntheticProfile()->count());
    }

    public function test_cannot_unset_is_synthetic_while_profile_exists(): void
    {
        $user = User::factory()->synthetic()->create();

        try {
            $user->is_synthetic = false;
            $user->save();
            $this->fail('Expected DomainException when unsetting is_synthetic with an existing profile.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Cannot unset is_synthetic while the user has a synthetic profile.',
                $exception->getMessage(),
            );
        }

        $user->refresh();

        $this->assertTrue($user->is_synthetic);
        $this->assertNotNull($user->syntheticProfile);
    }

    public function test_can_unset_is_synthetic_when_profile_does_not_exist(): void
    {
        $user = User::factory()->create(['is_synthetic' => true]);

        $user->is_synthetic = false;
        $user->save();

        $user->refresh();

        $this->assertFalse($user->is_synthetic);
        $this->assertNull($user->syntheticProfile);
    }
}
