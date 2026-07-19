<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Models\SyntheticUserSession;
use App\Models\User;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyntheticUserSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_belongs_to_user(): void
    {
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->create();

        $this->assertTrue($session->user->is($user));
        $this->assertTrue($user->syntheticSessions->contains($session));
    }

    public function test_deleting_user_cascades_sessions(): void
    {
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseMissing('synthetic_user_sessions', [
            'id' => $session->id,
        ]);
    }

    public function test_start_creates_active_session_from_profile(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => 4,
            'actions_per_session_max' => 7,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->expects($this->once())
            ->method('between')
            ->with(4, 7)
            ->willReturn(5);

        $this->app->instance(RandomIntRange::class, $random);

        $session = app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));

        $this->assertSame(SyntheticSessionStatuses::ACTIVE, $session->status);
        $this->assertSame(5, $session->planned_actions);
        $this->assertSame(0, $session->completed_actions);
        $this->assertTrue($session->next_action_at->equalTo(Carbon::parse('2026-07-17 12:00:00')));
        $this->assertTrue($session->started_at->equalTo(Carbon::parse('2026-07-17 12:00:00')));
        $this->assertNull($session->completed_at);
        $this->assertNotSame('', (string) $session->session_seed);
        $this->assertSame(36, strlen((string) $session->session_seed));

        Carbon::setTestNow();
    }

    public function test_start_rejects_disabled_profile(): void
    {
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update(['is_enabled' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('profile is disabled');

        app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));
    }

    public function test_start_rejects_regular_user(): void
    {
        $user = User::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('is not a synthetic user');

        app(StartSyntheticUserSessionAction::class)->execute($user);
    }

    public function test_start_rejects_user_without_profile(): void
    {
        $user = User::factory()->create(['is_synthetic' => true]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not have a profile');

        app(StartSyntheticUserSessionAction::class)->execute($user);
    }

    public function test_factory_states(): void
    {
        $active = SyntheticUserSession::factory()->active()->create();
        $completed = SyntheticUserSession::factory()->completed()->create();
        $failed = SyntheticUserSession::factory()->failed()->create();
        $due = SyntheticUserSession::factory()->due()->create();
        $notDue = SyntheticUserSession::factory()->notDue()->create();

        $this->assertTrue($active->isActive());
        $this->assertTrue($completed->isCompleted());
        $this->assertTrue($failed->isFailed());
        $this->assertTrue($due->next_action_at->lte(now()));
        $this->assertTrue($notDue->next_action_at->gt(now()));
        $this->assertNotNull($active->user->syntheticProfile);
    }
}
