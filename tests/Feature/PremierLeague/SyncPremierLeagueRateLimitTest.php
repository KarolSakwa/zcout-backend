<?php

namespace Tests\Feature\PremierLeague;

use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use App\PremierLeague\Support\FootballDataRequestThrottler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePremierLeagueApiTimer;
use Tests\TestCase;

class SyncPremierLeagueRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['zcout_premier_league.expected_club_count' => 20]);
        putenv('FOOTBALL_DATA_TOKEN=test-token');
        $_ENV['FOOTBALL_DATA_TOKEN'] = 'test-token';
        $_SERVER['FOOTBALL_DATA_TOKEN'] = 'test-token';
    }

    public function test_dry_run_recovers_after_single_429_and_fetches_dataset(): void
    {
        $timer = new FakePremierLeagueApiTimer();
        $this->bindClient($timer);

        $teams = [
            ['id' => 1, 'name' => 'Arsenal FC'],
            ['id' => 91, 'name' => 'Coventry City FC'],
        ];
        for ($i = 2; $i <= 19; $i++) {
            $teams[] = ['id' => 100 + $i, 'name' => "Filler Club {$i} FC"];
        }

        Http::fake(function ($request) use ($teams) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                static $teamsAttempts = 0;
                $teamsAttempts++;

                if ($teamsAttempts === 1) {
                    return Http::response('Wait 55 seconds', 429, ['Retry-After' => '55']);
                }

                return Http::response(['teams' => $teams], 200);
            }

            if (preg_match('#/teams/(\d+)$#', $request->url(), $matches)) {
                $ext = (int) $matches[1];

                return Http::response([
                    'squad' => [[
                        'id' => 70000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Right Back',
                        'nationality' => 'ENG',
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $beforeClubs = DB::table('clubs')->count();
        $beforePlayers = DB::table('players')->count();

        $report = app(PremierLeagueSeasonSynchronizer::class)->sync(dryRun: true);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertTrue($report->dryRun);
        $this->assertFalse($report->applied);
        $this->assertSame($beforeClubs, DB::table('clubs')->count());
        $this->assertSame($beforePlayers, DB::table('players')->count());
        $this->assertContains(57.0, $timer->sleeps);
        $this->assertGreaterThanOrEqual(21, count(Http::recorded()));
    }

    public function test_rate_limit_exhaustion_fails_before_database_writes(): void
    {
        $timer = new FakePremierLeagueApiTimer();
        $this->bindClient($timer, maxRetries: 2);

        DB::table('clubs')->insert([
            'external_id' => 1,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeName = (string) DB::table('clubs')->where('external_id', 1)->value('name');

        Http::fake([
            'https://api.football-data.org/v4/*' => Http::response('Wait 1 seconds', 429, ['Retry-After' => '1']),
        ]);

        $report = app(PremierLeagueSeasonSynchronizer::class)->sync();

        $this->assertFalse($report->success);
        $this->assertStringContainsString('rate limit exceeded', strtolower($report->errors[0] ?? ''));
        $this->assertSame($beforeName, DB::table('clubs')->where('external_id', 1)->value('name'));
        $this->assertSame(1, DB::table('clubs')->count());
    }

    private function bindClient(FakePremierLeagueApiTimer $timer, int $maxRetries = 3): void
    {
        config([
            'zcout_premier_league.api.minimum_request_interval_seconds' => 0,
            'zcout_premier_league.api.max_requests_per_minute' => 1000,
            'zcout_premier_league.api.rate_limit_retry_margin_seconds' => 2,
            'zcout_premier_league.api.max_rate_limit_retries' => $maxRetries,
            'zcout_premier_league.api.rate_limit_fallback_wait_seconds' => 60,
        ]);

        $client = new PremierLeagueApiClient(
            'test-token',
            $timer,
            $timer,
            new FootballDataRequestThrottler($timer, $timer, 0, 1000, 60),
        );

        $this->app->instance(PremierLeagueApiClient::class, $client);
        $this->app->instance(PremierLeagueSeasonSynchronizer::class, new PremierLeagueSeasonSynchronizer($client));
    }
}
