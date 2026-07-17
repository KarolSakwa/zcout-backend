<?php

namespace Tests\Feature\Console;

use App\Actions\ResolveVoterContextAction;
use App\Actions\ResolveVoterIdentityAction;
use App\Models\User;
use App\Services\Ranking\AttributeRankingService;
use App\Simulation\Synthetic\RunSyntheticUserSessionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class RunSyntheticUserSessionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        config([
            'zcout_matchmaking.intent_mix' => [
                'calibration' => 0.0,
                'production' => 1.0,
            ],
            'zcout_matchmaking.production_tier_mix' => [
                'A' => 1.0,
                'B' => 0.0,
                'C' => 0.0,
            ],
            'zcout_matchmaking.production_position_profile_mix' => [
                'exact' => 1.0,
                'adjacent' => 0.0,
                'same_side' => 0.0,
                'any' => 0.0,
            ],
            'zcout_matchmaking.production_gap_profile_mix' => [
                'close' => 0.0,
                'medium' => 1.0,
            ],
            'zcout_matchmaking.attribute_scope_mix' => [
                'both' => 1.0,
                'gk' => 0.0,
            ],
        ]);

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andReturn([
                    'rank' => null,
                    'is_top_ten' => false,
                ]);
        });

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_command_fails_for_missing_user(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => 999999,
            '--actions' => 1,
            '--profile' => 'casual',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('was not found', Artisan::output());
    }

    public function test_command_fails_for_invalid_profile(): void
    {
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 1,
            '--profile' => 'invalid-profile',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'The --profile option must be one of: expert, casual, noisy.',
            Artisan::output(),
        );
    }

    public function test_command_rejects_biased_profile(): void
    {
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 1,
            '--profile' => 'biased',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'The --profile option must be one of: expert, casual, noisy.',
            Artisan::output(),
        );
    }

    public function test_command_fails_for_non_positive_actions(): void
    {
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 0,
            '--profile' => 'casual',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--actions option must be a positive integer', Artisan::output());
    }

    public function test_command_fails_when_user_id_is_missing(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--actions' => 1,
            '--profile' => 'casual',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--user-id option is required', Artisan::output());
    }

    public function test_successful_vote_action_persists_vote_and_updates_ratings(): void
    {
        $fixture = $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 1,
            '--profile' => 'expert',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('votes', [
            'user_id' => $user->id,
            'source' => 'duel',
        ]);
        $this->assertDatabaseHas('player_attribute_ratings', [
            'player_id' => $fixture['player_a_id'],
            'attribute_id' => $fixture['attribute_id'],
        ]);
        $this->assertDatabaseHas('player_attribute_ratings', [
            'player_id' => $fixture['player_b_id'],
            'attribute_id' => $fixture['attribute_id'],
        ]);
        $this->assertStringContainsString('Session completed', Artisan::output());
    }

    public function test_action_clears_voter_lock(): void
    {
        $fixture = $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();
        $voterHash = 'user:' . $user->id;

        app(RunSyntheticUserSessionAction::class)->execute(
            user: $user,
            profile: 'expert',
            actions: 1,
            sessionId: '00000000-0000-4000-8000-000000000002',
            onAction: null,
        );

        $this->assertDatabaseMissing('voter_duel_locks', [
            'voter_hash' => $voterHash,
        ]);
    }

    public function test_missing_live_rating_results_in_skip(): void
    {
        config([
            'zcout_matchmaking.intent_mix' => [
                'calibration' => 1.0,
                'production' => 0.0,
            ],
        ]);

        $fixture = $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();

        DB::table('player_attribute_ratings')
            ->where('player_id', $fixture['player_b_id'])
            ->delete();

        $lines = [];
        app(RunSyntheticUserSessionAction::class)->execute(
            user: $user,
            profile: 'casual',
            actions: 1,
            sessionId: '00000000-0000-4000-8000-000000000003',
            onAction: function ($result) use (&$lines): void {
                $lines[] = $result->formatLine();
            },
        );

        $this->assertTrue(collect($lines)->contains(
            fn (string $line) => str_contains($line, 'decision=skip') && str_contains($line, 'reason=missing_live_rating'),
        ));
        $this->assertDatabaseHas('duel_skips', [
            'voter_hash' => 'user:' . $user->id,
        ]);
    }

    public function test_multiple_actions_use_same_user_history(): void
    {
        $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 2,
            '--profile' => 'expert',
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('[1/2]', $output);
        $this->assertStringContainsString('[2/2]', $output);
        $this->assertGreaterThanOrEqual(1, DB::table('votes')->where('user_id', $user->id)->count());
        $this->assertSame($user->id, (int) DB::table('votes')->where('user_id', $user->id)->value('user_id'));
    }

    public function test_session_does_not_create_simulation_lab_records(): void
    {
        $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();

        Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 1,
            '--profile' => 'casual',
        ]);

        $this->assertSame(0, DB::table('simulation_runs')->count());
        $this->assertSame(0, DB::table('simulation_run_events')->count());
    }

    public function test_synthetic_session_uses_same_voter_identity_as_production_flow(): void
    {
        $user = User::factory()->create();

        Auth::login($user);

        $matchmakingRequest = Request::create('/', 'GET');
        $matchmakingRequest->setUserResolver(static fn () => $user);
        app()->instance('request', $matchmakingRequest);

        $matchmakingContext = app(ResolveVoterContextAction::class)->handle();

        $voteRequest = Request::create('/api/votes', 'POST');
        $voteRequest->setUserResolver(static fn () => $user);
        app()->instance('request', $voteRequest);

        $voteIdentity = app(ResolveVoterIdentityAction::class)->execute($voteRequest);

        Auth::logout();

        $this->assertSame('ok', $matchmakingContext['status']);
        $this->assertSame('user:' . $user->id, $matchmakingContext['voter_hash']);
        $this->assertSame(
            hash_hmac('sha256', 'user:' . $user->id, (string) config('app.key')),
            $matchmakingContext['vote_voter_hash'],
        );
        $this->assertSame('user:' . $user->id, $voteIdentity->lockKey);
        $this->assertSame($matchmakingContext['vote_voter_hash'], $voteIdentity->voterHash);
        $this->assertSame(['user:' . $user->id], $voteIdentity->lockKeys);
    }

    public function test_unexpected_exception_aborts_session_and_is_logged(): void
    {
        $this->seedMatchmakingFixture(includeRatings: true);
        $user = User::factory()->create();

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andThrow(new RuntimeException('boom'));
        });

        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user): bool {
                return $message === 'synthetic.session.unexpected_error'
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['action_index'] ?? null) === 1
                    && ($context['exception'] ?? null) === RuntimeException::class
                    && ($context['message'] ?? null) === 'boom'
                    && isset($context['session_id']);
            });
        $logger->shouldIgnoreMissing();
        Log::swap($logger);

        $exitCode = Artisan::call('zcout:synthetic-users:run-session', [
            '--user-id' => $user->id,
            '--actions' => 3,
            '--profile' => 'expert',
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Session aborted:', $output);
        $this->assertStringContainsString(RuntimeException::class, $output);
        $this->assertStringContainsString('boom', $output);
        $this->assertStringNotContainsString('[2/3]', $output);
        $this->assertStringNotContainsString('[3/3]', $output);
        $this->assertStringNotContainsString('Session completed', $output);
        $this->assertLessThanOrEqual(1, DB::table('votes')->where('user_id', $user->id)->count());
    }

    /**
     * @return array{
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int
     * }
     */
    private function seedMatchmakingFixture(bool $includeRatings): array
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'code' => 'ENG',
            'name' => 'ENGLAND',
            'iso2' => 'GB',
            'flag_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubAId = DB::table('clubs')->insertGetId([
            'name' => 'Club A',
            'slug' => 'club-a',
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubBId = DB::table('clubs')->insertGetId([
            'name' => 'Club B',
            'slug' => 'club-b',
            'color_primary' => '#333333',
            'color_secondary' => '#444444',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a',
            'club' => 'Club A',
            'number' => 2,
            'club_id' => $clubAId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b',
            'club' => 'Club B',
            'number' => 22,
            'club_id' => $clubBId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        DB::table('player_reputation_stats')->insert([
            [
                'player_id' => $playerAId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.0000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 45,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
            [
                'player_id' => $playerBId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.1000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 46,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
        ]);

        if ($includeRatings) {
            DB::table('player_attribute_ratings')->insert([
                [
                    'player_id' => $playerAId,
                    'attribute_id' => $attributeId,
                    'rating' => 42,
                    'votes_count' => 0,
                    'confidence' => 50,
                ],
                [
                    'player_id' => $playerBId,
                    'attribute_id' => $attributeId,
                    'rating' => 50,
                    'votes_count' => 0,
                    'confidence' => 50,
                ],
            ]);
        }

        return [
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ];
    }
}
