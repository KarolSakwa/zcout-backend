<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Scouting\PreflightDuelVoteUniqueIndexCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PreflightDuelVoteUniqueIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_is_blocked_in_production_without_force(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $command = new class extends PreflightDuelVoteUniqueIndexCommand
        {
            public function option($key = null)
            {
                return match ($key) {
                    'apply' => true,
                    'force' => false,
                    default => parent::option($key),
                };
            }
        };

        $this->assertTrue($command->isProductionApplyBlocked());
    }

    public function test_apply_is_allowed_in_production_with_force(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $command = new class extends PreflightDuelVoteUniqueIndexCommand
        {
            public function option($key = null)
            {
                return match ($key) {
                    'apply' => true,
                    'force' => true,
                    default => parent::option($key),
                };
            }
        };

        $this->assertFalse($command->isProductionApplyBlocked());
    }

    public function test_artisan_apply_fails_in_production_without_force(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $exitCode = Artisan::call('zcout:preflight-duel-vote-unique-index', ['--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Refusing --apply in production without --force.',
            Artisan::output()
        );
    }
}
