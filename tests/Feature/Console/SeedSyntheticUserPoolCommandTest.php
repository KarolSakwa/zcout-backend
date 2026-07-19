<?php

namespace Tests\Feature\Console;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use App\Simulation\Synthetic\SeedSyntheticUserPoolAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticPoolIdentity;
use App\Simulation\Synthetic\SyntheticPoolProfileAllocator;
use App\Simulation\Synthetic\SyntheticProfilePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SeedSyntheticUserPoolCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_three_creates_exactly_three_users_and_profiles(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 3,
            '--pool' => 'default',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertSame(3, DB::table('synthetic_user_profiles')->count());

        for ($index = 1; $index <= 3; $index++) {
            $user = User::query()
                ->where('synthetic_pool_key', 'default')
                ->where('synthetic_pool_index', $index)
                ->first();
            $this->assertNotNull($user);
            $this->assertTrue($user->is_synthetic);
            $this->assertSame(UserRole::USER, $user->role);
            $this->assertSame(InfluenceProfile::USER_DEFAULT, $user->influence_profile);
            $this->assertNotNull($user->email_verified_at);
            $this->assertNotNull($user->syntheticProfile);
            $this->assertTrue($user->syntheticProfile->is_enabled);
            $this->assertContains(
                $user->syntheticProfile->decision_profile,
                SyntheticDecisionProfiles::ALLOWED,
            );
        }
    }

    public function test_ordinary_users_are_untouched(): void
    {
        $ordinary = User::factory()->create(['email' => 'human@example.com']);

        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 2]);

        $ordinary->refresh();
        $this->assertSame('human@example.com', $ordinary->email);
        $this->assertFalse($ordinary->is_synthetic);
        $this->assertNull($ordinary->synthetic_pool_key);
    }

    public function test_rerun_is_idempotent(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 3]);
        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 3]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertStringContainsString('Created: 0', $output);
        $this->assertStringContainsString('Target already satisfied', $output);
    }

    public function test_growing_pool_creates_only_missing_members(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 3]);
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 5]);

        $this->assertSame(5, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertTrue(
            User::query()
                ->where('synthetic_pool_key', 'default')
                ->where('synthetic_pool_index', 5)
                ->exists(),
        );
    }

    public function test_smaller_count_does_not_delete_members(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 5]);
        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 2]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(5, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertStringContainsString('Pool above target: yes', Artisan::output());
    }

    public function test_emails_and_names_are_deterministic(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 2, '--pool' => 'alpha']);
        $identity = app(SyntheticPoolIdentity::class);

        $user = User::query()
            ->where('synthetic_pool_key', 'alpha')
            ->where('synthetic_pool_index', 1)
            ->firstOrFail();

        $this->assertSame($identity->email('alpha', 1), $user->email);
        $this->assertSame($identity->displayName('alpha', 1), $user->name);
    }

    public function test_profiles_match_allocator_and_full_presets(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 10,
            '--expert-percent' => 20,
            '--casual-percent' => 50,
            '--noisy-percent' => 30,
        ]);

        $allocator = app(SyntheticPoolProfileAllocator::class);
        $users = User::query()
            ->where('synthetic_pool_key', 'default')
            ->with('syntheticProfile')
            ->orderBy('synthetic_pool_index')
            ->get();

        foreach ($users as $user) {
            $expectedProfile = $allocator->allocate(
                'default',
                (int) $user->synthetic_pool_index,
                20,
                50,
                30,
            );
            $this->assertSame($expectedProfile, $user->syntheticProfile->decision_profile);
            $this->assertContains($expectedProfile, SyntheticDecisionProfiles::ALLOWED);
            $this->assertNotSame('biased', $user->syntheticProfile->decision_profile);
            $this->assertProfileMatchesPreset($user->syntheticProfile, $expectedProfile);
        }
    }

    public function test_rerun_does_not_modify_manually_changed_existing_profile(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--expert-percent' => 100,
            '--casual-percent' => 0,
            '--noisy-percent' => 0,
        ]);

        $user = User::query()
            ->where('synthetic_pool_key', 'default')
            ->where('synthetic_pool_index', 1)
            ->with('syntheticProfile')
            ->firstOrFail();

        $this->assertProfileMatchesPreset($user->syntheticProfile, SyntheticDecisionProfiles::EXPERT);

        $user->syntheticProfile->update([
            'skip_probability' => 0.33,
            'decision_accuracy' => 0.41,
            'noise_level' => 0.22,
            'actions_per_session_max' => 11,
        ]);

        Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--expert-percent' => 100,
            '--casual-percent' => 0,
            '--noisy-percent' => 0,
        ]);

        $user->syntheticProfile->refresh();
        $this->assertEqualsWithDelta(0.33, $user->syntheticProfile->skip_probability, 1e-9);
        $this->assertEqualsWithDelta(0.41, $user->syntheticProfile->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta(0.22, $user->syntheticProfile->noise_level, 1e-9);
        $this->assertSame(11, $user->syntheticProfile->actions_per_session_max);
        $this->assertSame(SyntheticDecisionProfiles::EXPERT, $user->syntheticProfile->decision_profile);
    }

    public function test_growing_pool_applies_presets_only_to_new_members(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--expert-percent' => 0,
            '--casual-percent' => 100,
            '--noisy-percent' => 0,
        ]);

        $existing = User::query()
            ->where('synthetic_pool_key', 'default')
            ->where('synthetic_pool_index', 1)
            ->with('syntheticProfile')
            ->firstOrFail();
        $existing->syntheticProfile->update(['skip_probability' => 0.01]);

        Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 3,
            '--expert-percent' => 0,
            '--casual-percent' => 0,
            '--noisy-percent' => 100,
        ]);

        $existing->syntheticProfile->refresh();
        $this->assertEqualsWithDelta(0.01, $existing->syntheticProfile->skip_probability, 1e-9);
        $this->assertSame(SyntheticDecisionProfiles::CASUAL, $existing->syntheticProfile->decision_profile);

        $newMembers = User::query()
            ->where('synthetic_pool_key', 'default')
            ->whereIn('synthetic_pool_index', [2, 3])
            ->with('syntheticProfile')
            ->get();

        $this->assertCount(2, $newMembers);
        foreach ($newMembers as $member) {
            $this->assertProfileMatchesPreset($member->syntheticProfile, SyntheticDecisionProfiles::NOISY);
        }
    }

    public function test_dry_run_does_not_write_and_reports_plan(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 4,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertStringContainsString('Synthetic pool dry run', $output);
        $this->assertStringContainsString('Would create: 4', $output);
        $this->assertStringContainsString('No data was changed', $output);
    }

    public function test_separate_pools_are_independent(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 2, '--pool' => 'a']);
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 3, '--pool' => 'b']);

        $this->assertSame(2, User::query()->where('synthetic_pool_key', 'a')->count());
        $this->assertSame(3, User::query()->where('synthetic_pool_key', 'b')->count());
    }

    public function test_manual_synthetic_user_is_not_counted_in_pool(): void
    {
        User::factory()->synthetic()->create();

        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 2]);

        $this->assertSame(2, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertSame(1, User::query()->where('is_synthetic', true)->whereNull('synthetic_pool_key')->count());
    }

    public function test_conflict_is_reported_and_later_indexes_continue(): void
    {
        $identity = app(SyntheticPoolIdentity::class);

        // Inconsistent managed-looking row for index 1 (bypass model hooks).
        DB::table('users')->insert([
            'name' => 'Broken',
            'email' => $identity->email('default', 1),
            'password' => Hash::make('password'),
            'role' => UserRole::USER->value,
            'influence_profile' => InfluenceProfile::USER_DEFAULT->value,
            'is_synthetic' => false,
            'synthetic_pool_key' => 'default',
            'synthetic_pool_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 3]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Conflicts: 1', $output);
        $this->assertStringContainsString('Conflict index=1 reason=is_synthetic_false', $output);
        $this->assertSame(2, User::query()
            ->where('synthetic_pool_key', 'default')
            ->where('is_synthetic', true)
            ->count());
        $this->assertTrue(
            User::query()
                ->where('synthetic_pool_key', 'default')
                ->where('synthetic_pool_index', 3)
                ->exists(),
        );
    }

    public function test_invalid_count_and_pool_and_percents(): void
    {
        $this->assertSame(1, Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 0]));
        $this->assertStringContainsString('positive integer', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => SeedSyntheticUserPoolAction::MAX_COUNT + 1,
        ]));
        $this->assertStringContainsString('must not exceed', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--pool' => 'Bad Pool',
        ]));
        $this->assertStringContainsString('Invalid synthetic pool key', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--expert-percent' => 50,
            '--casual-percent' => 50,
            '--noisy-percent' => 50,
        ]));
        $this->assertStringContainsString('sum to exactly 100', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 1,
            '--expert-percent' => -1,
            '--casual-percent' => 101,
            '--noisy-percent' => 0,
        ]));
    }

    public function test_unique_conflict_results_in_single_record(): void
    {
        User::factory()->syntheticPoolMember('default', 1, SyntheticDecisionProfiles::CASUAL)->create();

        $result = app(SeedSyntheticUserPoolAction::class)->execute(
            poolKey: 'default',
            targetCount: 1,
            expertPercent: 15,
            casualPercent: 70,
            noisyPercent: 15,
            dryRun: false,
        );

        $this->assertSame(1, $result->existingValid);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->conflicts);
        $this->assertSame(1, User::query()->where('synthetic_pool_key', 'default')->count());
    }

    public function test_email_owned_by_other_user_is_conflict(): void
    {
        $identity = app(SyntheticPoolIdentity::class);
        User::factory()->create([
            'email' => $identity->email('default', 1),
        ]);

        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 1]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('email_owned_by_other_user', Artisan::output());
        $this->assertSame(0, User::query()->where('synthetic_pool_key', 'default')->count());
    }

    public function test_password_is_hashed_and_not_printed(): void
    {
        Artisan::call('zcout:synthetic-users:seed-pool', ['--count' => 1]);
        $output = Artisan::output();
        $user = User::query()->where('synthetic_pool_key', 'default')->firstOrFail();

        $this->assertNotSame('', (string) $user->password);
        $this->assertTrue(strlen((string) $user->getRawOriginal('password')) > 20);
        $this->assertStringNotContainsString((string) $user->getRawOriginal('password'), $output);
    }

    public function test_dry_run_reports_profiles_without_writing_presets(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:seed-pool', [
            '--count' => 3,
            '--expert-percent' => 100,
            '--casual-percent' => 0,
            '--noisy-percent' => 0,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, User::query()->where('synthetic_pool_key', 'default')->count());
        $this->assertSame(0, DB::table('synthetic_user_profiles')->count());
        $this->assertStringContainsString('Would create: 3', $output);
        $this->assertStringContainsString('Expert: 3', $output);
        $this->assertStringContainsString('Casual: 0', $output);
        $this->assertStringContainsString('Noisy: 0', $output);
    }

    private function assertProfileMatchesPreset($profile, string $archetype): void
    {
        $expected = SyntheticProfilePresets::for($archetype);

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
