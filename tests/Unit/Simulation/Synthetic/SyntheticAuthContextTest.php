<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Models\User;
use App\Simulation\Synthetic\AdvanceSyntheticUserSessionAction;
use App\Simulation\Synthetic\ExecuteSyntheticDuelAction;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\RunWithAuthenticatedUser;
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionActionResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Tests\TestCase;

final class SyntheticAuthContextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Auth::logout();
        app()->forgetInstance('request');

        parent::tearDown();
    }

    public function test_successful_advance_restores_previous_request(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $session = $this->startDueSession();
        $this->bindOkExecuteMock();

        $previousRequest = Request::create('/previous', 'GET');
        app()->instance('request', $previousRequest);

        app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);

        $this->assertSame($previousRequest, app('request'));
        $this->assertSame('/previous', app('request')->getPathInfo());
    }

    public function test_failed_advance_restores_previous_request(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $session = $this->startDueSession();

        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willThrowException(new RuntimeException('boom'));
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);

        $previousRequest = Request::create('/previous-error', 'GET');
        app()->instance('request', $previousRequest);

        try {
            app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($previousRequest, app('request'));
        $this->assertSame('/previous-error', app('request')->getPathInfo());
    }

    public function test_previous_authenticated_user_is_restored(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $session = $this->startDueSession();
        $this->bindOkExecuteMock();

        $previousUser = User::factory()->create();
        Auth::login($previousUser);

        $previousRequest = Request::create('/previous', 'GET');
        $previousRequest->setUserResolver(static fn () => $previousUser);
        app()->instance('request', $previousRequest);

        app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);

        $this->assertTrue(Auth::check());
        $this->assertSame($previousUser->id, Auth::id());
        $this->assertSame($previousRequest, app('request'));
    }

    public function test_guest_remains_guest_after_advance(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $session = $this->startDueSession();
        $this->bindOkExecuteMock();

        Auth::logout();
        $this->assertFalse(Auth::check());

        app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);

        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }

    public function test_second_session_in_same_process_uses_own_identity(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $seenUserIds = [];
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function (
                User $user,
                string $decisionProfile,
                string $sessionSeed,
                int $actionIndex,
                int $plannedActions,
            ) use (&$seenUserIds): SyntheticSessionActionResult {
                $seenUserIds[] = $user->id;
                $this->assertSame($user->id, Auth::id());
                $this->assertSame($user->id, app('request')->user()?->id);

                return new SyntheticSessionActionResult(
                    actionIndex: $actionIndex,
                    plannedActions: $plannedActions,
                    duelId: 1,
                    attributeKey: 'pace',
                    playerAId: 1,
                    playerBId: 2,
                    decision: 'vote',
                    winnerId: 1,
                    status: 'ok',
                );
            },
        );
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);

        $sessionA = $this->startDueSession();
        $sessionB = $this->startDueSession();
        $this->assertNotSame($sessionA->user_id, $sessionB->user_id);

        app(AdvanceSyntheticUserSessionAction::class)->execute($sessionA->id);
        app(AdvanceSyntheticUserSessionAction::class)->execute($sessionB->id);

        $this->assertSame([$sessionA->user_id, $sessionB->user_id], $seenUserIds);
        $this->assertFalse(Auth::check());
    }

    public function test_helper_restores_request_after_callback_exception(): void
    {
        $user = User::factory()->synthetic()->create();
        $previousRequest = Request::create('/kept', 'GET');
        app()->instance('request', $previousRequest);

        try {
            app(RunWithAuthenticatedUser::class)->execute($user, function () use ($user): void {
                $this->assertSame($user->id, Auth::id());
                throw new RuntimeException('inside');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($previousRequest, app('request'));
        $this->assertFalse(Auth::check());
    }

    private function startDueSession(): \App\Models\SyntheticUserSession
    {
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 2,
            'delay_seconds_min' => 1,
            'delay_seconds_max' => 1,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(
            function (int $min, int $max): int {
                return $min === $max ? $min : $min;
            },
        );
        $this->app->instance(RandomIntRange::class, $random);

        return app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));
    }

    private function bindOkExecuteMock(): void
    {
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function (
                User $user,
                string $decisionProfile,
                string $sessionSeed,
                int $actionIndex,
                int $plannedActions,
            ): SyntheticSessionActionResult {
                return new SyntheticSessionActionResult(
                    actionIndex: $actionIndex,
                    plannedActions: $plannedActions,
                    duelId: 1,
                    attributeKey: 'pace',
                    playerAId: 1,
                    playerBId: 2,
                    decision: 'vote',
                    winnerId: 1,
                    status: 'ok',
                );
            },
        );
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);
    }
}
