<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Simulation\Synthetic\GetSyntheticWorldStatusAction;
use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class SyntheticWorldStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'synthetic_world.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_standard_output_contains_all_sections(): void
    {
        Carbon::setTestNow('2026-07-18 14:25:00');
        User::factory()->synthetic()->create();

        $exitCode = Artisan::call('zcout:synthetic-world:status');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        foreach ([
            'Synthetic World Status',
            'Date:',
            'Timezone:',
            'Automation:',
            'Health:',
            'Users',
            'Profiles',
            'World sessions',
            'Manual sessions',
            'Execution',
            'Activity',
            'Current session last-action states',
            'Failures',
            'Locks',
            'Warnings',
        ] as $section) {
            $this->assertStringContainsString($section, $output);
        }
    }

    public function test_empty_report_is_readable_and_warnings_do_not_fail(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');

        $exitCode = Artisan::call('zcout:synthetic-world:status');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synthetic total: 0', $output);
        $this->assertStringContainsString('no_enabled_synthetic_users', $output);
    }

    public function test_json_output_is_parseable_without_text_header(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        User::factory()->synthetic()->create();

        $exitCode = Artisan::call('zcout:synthetic-world:status', ['--json' => true]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exitCode);
        $this->assertStringStartsWith('{', $output);
        $this->assertStringNotContainsString('Synthetic World Status', $output);

        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-07-18', $decoded['date']);
        $this->assertIsInt($decoded['users']['synthetic_users_total']);
        $this->assertIsArray($decoded['warnings']);
    }

    public function test_invalid_date_returns_failure(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-world:status', ['--date' => 'not-a-date']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid --date', Artisan::output());
    }

    public function test_critical_action_exception_returns_failure(): void
    {
        $action = $this->createMock(GetSyntheticWorldStatusAction::class);
        $action->method('execute')->willThrowException(new RuntimeException('boom'));
        $this->app->instance(GetSyntheticWorldStatusAction::class, $action);

        $exitCode = Artisan::call('zcout:synthetic-world:status');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Synthetic world status failed', Artisan::output());
    }

    public function test_invalid_calendar_date_returns_failure(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-world:status', ['--date' => '2026-02-31']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid --date', Artisan::output());
    }

    public function test_command_does_not_invoke_tick_and_works_when_automation_disabled(): void
    {
        config(['synthetic_world.enabled' => false]);

        $tick = $this->createMock(TickSyntheticWorldAction::class);
        $tick->expects($this->never())->method('execute');
        $this->app->instance(TickSyntheticWorldAction::class, $tick);

        $exitCode = Artisan::call('zcout:synthetic-world:status');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Automation: disabled', Artisan::output());
    }

    public function test_verbose_includes_capped_details_section(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        \App\Models\SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->failed()->create();

        $this->artisan('zcout:synthetic-world:status', ['-v' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Details (verbose, capped at 20 each)')
            ->expectsOutputToContain('Failed sessions:');
    }

    public function test_json_shape_matches_dto_array(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $expected = app(GetSyntheticWorldStatusAction::class)->execute()->toArray();

        Artisan::call('zcout:synthetic-world:status', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($expected['date'], $decoded['date']);
        $this->assertSame($expected['health'], $decoded['health']);
        $this->assertSame($expected['users'], $decoded['users']);
        $this->assertArrayHasKey('details', $decoded);
    }
}
