<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StartSyntheticUserSessionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_for_missing_user(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:start-session', [
            '--user-id' => 999999,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('was not found', Artisan::output());
    }

    public function test_command_fails_for_regular_user(): void
    {
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:start-session', [
            '--user-id' => $user->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not a synthetic user', Artisan::output());
    }

    public function test_command_fails_for_user_without_profile(): void
    {
        $user = User::factory()->create(['is_synthetic' => true]);

        $exitCode = Artisan::call('zcout:synthetic-users:start-session', [
            '--user-id' => $user->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not have a profile', Artisan::output());
    }

    public function test_command_fails_for_disabled_profile(): void
    {
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update(['is_enabled' => false]);

        $exitCode = Artisan::call('zcout:synthetic-users:start-session', [
            '--user-id' => $user->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('profile is disabled', Artisan::output());
    }

    public function test_command_starts_session_and_prints_details(): void
    {
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => 3,
            'actions_per_session_max' => 3,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturn(3);
        $this->app->instance(RandomIntRange::class, $random);

        $exitCode = Artisan::call('zcout:synthetic-users:start-session', [
            '--user-id' => $user->id,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Session started', $output);
        $this->assertStringContainsString('Session ID:', $output);
        $this->assertStringContainsString('User: ' . $user->id, $output);
        $this->assertStringContainsString('Profile: expert', $output);
        $this->assertStringContainsString('Planned actions: 3', $output);
        $this->assertStringContainsString('Completed actions: 0', $output);
        $this->assertStringContainsString('Next action at:', $output);
        $this->assertStringContainsString('Session seed:', $output);
        $this->assertDatabaseHas('synthetic_user_sessions', [
            'user_id' => $user->id,
            'status' => 'active',
            'planned_actions' => 3,
            'completed_actions' => 0,
        ]);
    }

    public function test_command_has_no_actions_or_profile_options(): void
    {
        $definition = app(\App\Console\Commands\Simulation\SyntheticUsers\StartSyntheticUserSessionCommand::class)->getDefinition();

        $this->assertTrue($definition->hasOption('user-id'));
        $this->assertFalse($definition->hasOption('actions'));
        $this->assertFalse($definition->hasOption('profile'));
    }
}
