<?php

namespace Tests\Unit\PremierLeague;

use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\Support\FootballDataRequestThrottler;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePremierLeagueApiTimer;
use Tests\TestCase;

class PremierLeagueApiClientRateLimitTest extends TestCase
{
    private function clientWithTimer(FakePremierLeagueApiTimer $timer, int $maxRetries = 3): PremierLeagueApiClient
    {
        config([
            'zcout_premier_league.api.minimum_request_interval_seconds' => 7,
            'zcout_premier_league.api.max_requests_per_minute' => 9,
            'zcout_premier_league.api.rate_limit_window_seconds' => 60,
            'zcout_premier_league.api.rate_limit_retry_margin_seconds' => 2,
            'zcout_premier_league.api.max_rate_limit_retries' => $maxRetries,
            'zcout_premier_league.api.rate_limit_fallback_wait_seconds' => 60,
        ]);

        return new PremierLeagueApiClient(
            'test-token',
            $timer,
            $timer,
            new FootballDataRequestThrottler($timer, $timer, 7, 9, 60),
        );
    }

    public function test_429_with_retry_after_waits_margin_and_retries_same_request(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::sequence()
                ->push('Wait 55 seconds', 429, ['Retry-After' => '55'])
                ->push(['teams' => [['id' => 1, 'name' => 'Arsenal FC']]], 200),
        ]);

        $teams = $this->clientWithTimer($timer)->fetchCompetitionTeams();

        $this->assertSame([['external_id' => 1, 'name' => 'Arsenal FC']], $teams);
        $this->assertContains(57.0, $timer->sleeps);
        Http::assertSentCount(2);
    }

    public function test_429_with_body_wait_message_waits_margin_and_retries(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::sequence()
                ->push('You reached your request limit. Wait 55 seconds.', 429)
                ->push(['teams' => [['id' => 1, 'name' => 'Arsenal FC']]], 200),
        ]);

        $teams = $this->clientWithTimer($timer)->fetchCompetitionTeams();

        $this->assertSame([['external_id' => 1, 'name' => 'Arsenal FC']], $teams);
        $this->assertContains(57.0, $timer->sleeps);
    }

    public function test_429_without_wait_hint_uses_fallback_plus_margin(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::sequence()
                ->push('Too many requests', 429)
                ->push(['teams' => [['id' => 1, 'name' => 'Arsenal FC']]], 200),
        ]);

        $this->clientWithTimer($timer)->fetchCompetitionTeams();

        $this->assertContains(62.0, $timer->sleeps);
    }

    public function test_429_exhausts_retries_and_throws(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response('Wait 1 seconds', 429, ['Retry-After' => '1']),
        ]);

        $this->expectExceptionMessage('rate limit exceeded after 3 retries');

        try {
            $this->clientWithTimer($timer, maxRetries: 3)->fetchCompetitionTeams();
        } finally {
            $this->assertSame(4, count(Http::recorded()));
            $this->assertGreaterThanOrEqual(3, count(array_filter($timer->sleeps, fn (float $sleep) => $sleep >= 3.0)));
        }
    }

    public function test_non_429_http_error_is_not_retried_as_rate_limit(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response('Server error', 500),
        ]);

        $this->expectExceptionMessage('Failed to fetch PL teams: HTTP 500');

        try {
            $this->clientWithTimer($timer)->fetchCompetitionTeams();
        } finally {
            Http::assertSentCount(1);
            $this->assertSame([], $timer->sleeps);
        }
    }

    public function test_full_dataset_fetch_applies_minimum_interval_between_requests(): void
    {
        $timer = new FakePremierLeagueApiTimer();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response([
                    'teams' => array_map(
                        static fn (int $id) => ['id' => $id, 'name' => "Club {$id}"],
                        range(1, 20),
                    ),
                ], 200);
            }

            return Http::response(['squad' => []], 200);
        });

        $client = $this->clientWithTimer($timer);
        $client->fetchCompetitionTeams();

        for ($teamId = 1; $teamId <= 20; $teamId++) {
            $client->fetchTeamSquad($teamId);
        }

        $this->assertSame(20, count($timer->sleeps));
        $this->assertEqualsWithDelta(140.0, array_sum($timer->sleeps), 0.001);
        Http::assertSentCount(21);
    }
}
