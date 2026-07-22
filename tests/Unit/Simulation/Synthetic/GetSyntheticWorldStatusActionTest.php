<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Actions\Duels\AuthenticatedVoterLockKey;
use App\Models\SyntheticUserSession;
use App\Models\User;
use App\Simulation\Synthetic\GetSyntheticWorldStatusAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GetSyntheticWorldStatusActionTest extends TestCase
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

    public function test_empty_report_with_no_synthetic_users(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');

        $status = app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame('2026-07-18', $status->date);
        $this->assertSame(0, $status->synthetic_users_total);
        $this->assertSame('inactive', $status->health);
        $this->assertSame(0, $status->worldSessions['total']);
        $this->assertContains('no_enabled_synthetic_users', array_column($status->warnings, 'code'));
    }

    public function test_managed_and_manual_users_are_separated(): void
    {
        User::factory()->syntheticPoolMember('default', 1)->create();
        User::factory()->synthetic()->create();

        $status = app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame(2, $status->synthetic_users_total);
        $this->assertSame(1, $status->managed_pool_users);
        $this->assertSame(1, $status->manual_synthetic_users);
        $this->assertSame(2, $status->enabled_profiles);
    }

    public function test_enabled_disabled_and_missing_profile_counts(): void
    {
        User::factory()->synthetic()->create();
        $disabled = User::factory()->synthetic()->create();
        $disabled->syntheticProfile->update(['is_enabled' => false]);
        User::factory()->create(['is_synthetic' => true]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame(3, $status->synthetic_users_total);
        $this->assertSame(1, $status->enabled_profiles);
        $this->assertSame(1, $status->disabled_profiles);
        $this->assertSame(1, $status->missing_profiles);
        $this->assertContains('synthetic_users_missing_profiles', array_column($status->warnings, 'code'));
    }

    public function test_invalid_profile_does_not_abort_report(): void
    {
        $user = User::factory()->synthetic()->create();
        DB::table('synthetic_user_profiles')
            ->where('user_id', $user->id)
            ->update(['skip_probability' => 1.5]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute(includeDetails: true);

        $this->assertSame(1, $status->invalid_profiles);
        $this->assertSame('inactive', $status->health);
        $this->assertNotEmpty($status->invalidProfileDetails);
        $this->assertContains('invalid_profiles_present', array_column($status->warnings, 'code'));
    }

    public function test_profile_distribution_and_biased_as_invalid_unknown(): void
    {
        User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();
        User::factory()->synthetic(SyntheticDecisionProfiles::NOISY)->create();

        $biased = User::factory()->create(['is_synthetic' => true]);
        DB::table('synthetic_user_profiles')->insert([
            'user_id' => $biased->id,
            'decision_profile' => 'biased',
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame(1, $status->profiles['expert']);
        $this->assertSame(1, $status->profiles['casual']);
        $this->assertSame(1, $status->profiles['noisy']);
        $this->assertSame(1, $status->profiles['invalid_unknown']);
        $this->assertSame(1, $status->invalid_profiles);
    }

    public function test_world_and_manual_sessions_are_separated(): void
    {
        Carbon::setTestNow('2026-07-18 15:00:00');
        $user = User::factory()->synthetic()->create();

        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->completed()->create([
            'planned_actions' => 4,
            'completed_actions' => 4,
            'started_at' => '2026-07-18 10:00:00',
        ]);
        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 2)->failed()->create([
            'planned_actions' => 3,
            'completed_actions' => 1,
            'started_at' => '2026-07-18 11:00:00',
            'last_action_reason' => 'unexpected_error',
        ]);
        SyntheticUserSession::factory()->for($user)->create([
            'activity_date' => null,
            'started_at' => '2026-07-18 12:00:00',
            'status' => SyntheticSessionStatuses::ACTIVE,
            'planned_actions' => 2,
        ]);
        SyntheticUserSession::factory()->for($user)->world('2026-07-17', 1)->completed()->create([
            'started_at' => '2026-07-17 10:00:00',
        ]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute('2026-07-18');

        $this->assertSame(2, $status->worldSessions['total']);
        $this->assertSame(1, $status->worldSessions['completed']);
        $this->assertSame(1, $status->worldSessions['failed']);
        $this->assertSame(7, $status->worldSessions['planned_actions_sum']);
        $this->assertSame(5, $status->worldSessions['completed_actions_sum']);
        $this->assertEqualsWithDelta(5 / 7, $status->worldSessions['completion_ratio'], 0.0001);
        $this->assertSame(1, $status->manualSessions['started']);
        $this->assertSame(1, $status->manualSessions['active']);
        $this->assertSame(1, $status->failures['failed_sessions_today']);
        $this->assertSame(1, $status->failures['failed_today_by_reason']['unexpected_error']);
    }

    public function test_due_and_overdue_thresholds(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();

        SyntheticUserSession::factory()->for($user)->create([
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => '2026-07-18 11:59:30',
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
        ]);
        SyntheticUserSession::factory()->for($user)->create([
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => '2026-07-18 11:50:00',
            'activity_date' => '2026-07-18',
            'daily_session_index' => 2,
        ]);
        SyntheticUserSession::factory()->for($user)->create([
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => '2026-07-18 11:40:00',
            'activity_date' => '2026-07-18',
            'daily_session_index' => 3,
        ]);
        SyntheticUserSession::factory()->for($user)->create([
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => '2026-07-18 12:30:00',
            'activity_date' => null,
        ]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame(3, $status->execution['due_now']);
        $this->assertSame(2, $status->execution['overdue_1_min']);
        $this->assertSame(2, $status->execution['overdue_5_min']);
        $this->assertSame(1, $status->execution['overdue_15_min']);
        $this->assertNotNull($status->execution['oldest_overdue_session_id']);
        $this->assertContains('overdue_sessions_present', array_column($status->warnings, 'code'));
    }

    public function test_votes_and_skips_count_only_synthetic_duel_source(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $fixture = $this->seedVoteFixture();

        $managed = User::factory()->syntheticPoolMember('default', 1, SyntheticDecisionProfiles::EXPERT)->create();
        $manual = User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();
        $regular = User::factory()->create();

        $this->insertDuelVote($fixture, $managed->id, '2026-07-18 10:00:00');
        $this->insertDuelVote($fixture, $manual->id, '2026-07-18 11:00:00');
        $this->insertDuelVote($fixture, $regular->id, '2026-07-18 11:30:00');
        DB::table('votes')->insert([
            'source' => 'direct',
            'duel_id' => null,
            'attribute_id' => $fixture['attribute_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => null,
            'winner_id' => null,
            'user_id' => $managed->id,
            'value' => 70,
            'created_at' => '2026-07-18 11:45:00',
        ]);

        DB::table('duel_skips')->insert([
            'duel_id' => $fixture['duel_id'],
            'voter_hash' => AuthenticatedVoterLockKey::forUserId($managed->id),
            'user_id' => $managed->id,
            'created_at' => '2026-07-18 10:05:00',
            'updated_at' => '2026-07-18 10:05:00',
        ]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute('2026-07-18');

        $this->assertSame(2, $status->activity['synthetic_votes']);
        $this->assertSame(1, $status->activity['managed_pool_votes']);
        $this->assertSame(1, $status->activity['manual_synthetic_votes']);
        $this->assertSame(1, $status->activity['synthetic_skips']);
        $this->assertSame(2, $status->activity['unique_synthetic_voters']);
        $this->assertSame(1.0, $status->activity['average_votes_per_active_synthetic_user']);
        $this->assertSame(1, $status->activity['votes_by_profile']['expert']);
        $this->assertSame(1, $status->activity['votes_by_profile']['casual']);
    }

    public function test_stale_locks_use_authenticated_voter_lock_key(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $fixture = $this->seedVoteFixture();
        $user = User::factory()->synthetic()->create();

        DB::table('voter_duel_locks')->insert([
            'voter_hash' => AuthenticatedVoterLockKey::forUserId($user->id),
            'duel_id' => $fixture['duel_id'],
            'created_at' => '2026-07-18 11:58:00',
            'updated_at' => '2026-07-18 11:58:00',
        ]);

        $status = app(GetSyntheticWorldStatusAction::class)->execute(includeDetails: true);

        $this->assertSame(1, $status->locks['synthetic_locks_total']);
        $this->assertSame(1, $status->locks['stale_synthetic_locks_1_min']);
        $this->assertSame(0, $status->locks['stale_synthetic_locks_5_min']);
        $this->assertContains('stale_locks_present', array_column($status->warnings, 'code'));
        $this->assertSame($user->id, $status->staleLockDetails[0]['user_id']);
    }

    public function test_health_status_rules(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');

        config(['synthetic_world.enabled' => false]);
        $this->assertSame('inactive', app(GetSyntheticWorldStatusAction::class)->execute()->health);

        config(['synthetic_world.enabled' => true]);
        $this->assertSame('no_data', app(GetSyntheticWorldStatusAction::class)->execute()->health);

        $user = User::factory()->synthetic()->create();
        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->create([
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => '2026-07-18 11:50:00',
        ]);
        $this->assertSame('warning', app(GetSyntheticWorldStatusAction::class)->execute()->health);

        SyntheticUserSession::query()->update(['next_action_at' => '2026-07-18 11:40:00']);
        $this->assertSame('critical', app(GetSyntheticWorldStatusAction::class)->execute()->health);

        SyntheticUserSession::query()->update(['next_action_at' => '2026-07-18 12:30:00']);
        $this->assertSame('healthy', app(GetSyntheticWorldStatusAction::class)->execute()->health);
    }

    public function test_explicit_date_and_default_today(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        SyntheticUserSession::factory()->for($user)->world('2026-07-17', 1)->completed()->create();
        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->completed()->create();

        $today = app(GetSyntheticWorldStatusAction::class)->execute();
        $explicit = app(GetSyntheticWorldStatusAction::class)->execute('2026-07-17');

        $this->assertSame('2026-07-18', $today->date);
        $this->assertSame(1, $today->worldSessions['total']);
        $this->assertSame('2026-07-17', $explicit->date);
        $this->assertSame(1, $explicit->worldSessions['total']);
    }

    public function test_timezone_boundary_for_warsaw(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'Europe/Warsaw'));

        $fixture = $this->seedVoteFixture();
        $user = User::factory()->synthetic()->create();

        // 2026-07-18 00:00 Europe/Warsaw == 2026-07-17 22:00 UTC
        $this->insertDuelVote($fixture, $user->id, '2026-07-17 22:00:00');
        $this->insertDuelVote($fixture, $user->id, '2026-07-17 21:59:59');

        $status = app(GetSyntheticWorldStatusAction::class)->execute('2026-07-18');

        $this->assertSame('Europe/Warsaw', $status->timezone);
        $this->assertSame(1, $status->activity['synthetic_votes']);
    }

    public function test_dst_day_in_europe_warsaw(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        // DST starts 2026-03-29 02:00 -> 03:00 in Europe/Warsaw
        Carbon::setTestNow(Carbon::parse('2026-03-29 15:00:00', 'Europe/Warsaw'));

        $fixture = $this->seedVoteFixture();
        $user = User::factory()->synthetic()->create();
        $this->insertDuelVote($fixture, $user->id, '2026-03-29 01:30:00');

        $status = app(GetSyntheticWorldStatusAction::class)->execute('2026-03-29');

        $this->assertSame('2026-03-29', $status->date);
        $this->assertSame(1, $status->activity['synthetic_votes']);
    }

    public function test_invalid_date_throws_domain_exception(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid --date');

        app(GetSyntheticWorldStatusAction::class)->execute('18-07-2026');
    }

    public function test_status_is_read_only(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->create();
        $profileUpdatedAt = (string) $user->syntheticProfile->updated_at;
        $sessionUpdatedAt = (string) $session->updated_at;
        $sessionsBefore = SyntheticUserSession::query()->count();
        $votesBefore = DB::table('votes')->count();
        $locksBefore = DB::table('voter_duel_locks')->count();

        $tick = $this->createMock(TickSyntheticWorldAction::class);
        $tick->expects($this->never())->method('execute');
        $this->app->instance(TickSyntheticWorldAction::class, $tick);

        app(GetSyntheticWorldStatusAction::class)->execute();

        $this->assertSame($sessionsBefore, SyntheticUserSession::query()->count());
        $this->assertSame($votesBefore, DB::table('votes')->count());
        $this->assertSame($locksBefore, DB::table('voter_duel_locks')->count());
        $this->assertSame($profileUpdatedAt, (string) $user->syntheticProfile->fresh()->updated_at);
        $this->assertSame($sessionUpdatedAt, (string) $session->fresh()->updated_at);
    }

    /**
     * @return array{attribute_id: int, duel_id: int, player_a_id: int, player_b_id: int}
     */
    private function seedVoteFixture(): array
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a-status',
            'club' => 'Club A',
            'number' => 1,
        ]);
        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b-status',
            'club' => 'Club B',
            'number' => 2,
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'attribute_id' => $attributeId,
            'created_at' => now(),
        ]);

        return [
            'attribute_id' => $attributeId,
            'duel_id' => $duelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ];
    }

    /**
     * @param array{attribute_id: int, duel_id: int, player_a_id: int, player_b_id: int} $fixture
     */
    private function insertDuelVote(array $fixture, int $userId, string $createdAt): void
    {
        DB::table('votes')->insert([
            'source' => 'duel',
            'duel_id' => $fixture['duel_id'],
            'attribute_id' => $fixture['attribute_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'user_id' => $userId,
            'voter_hash' => hash_hmac('sha256', AuthenticatedVoterLockKey::forUserId($userId), (string) config('app.key')),
            'created_at' => $createdAt,
        ]);
    }
}
