<?php

namespace App\PremierLeague;

use App\PremierLeague\Support\FootballDataRateLimitWaitParser;
use App\PremierLeague\Support\FootballDataRequestThrottler;
use App\PremierLeague\Support\PremierLeagueApiClock;
use App\PremierLeague\Support\PremierLeagueApiSleeper;
use App\PremierLeague\Support\SleepPremierLeagueApiSleeper;
use App\PremierLeague\Support\SystemPremierLeagueApiClock;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PremierLeagueApiClient
{
    private readonly PremierLeagueApiClock $clock;

    private readonly PremierLeagueApiSleeper $sleeper;

    private readonly FootballDataRequestThrottler $throttler;

    public function __construct(
        private readonly ?string $token = null,
        ?PremierLeagueApiClock $clock = null,
        ?PremierLeagueApiSleeper $sleeper = null,
        ?FootballDataRequestThrottler $throttler = null,
    ) {
        $this->clock = $clock ?? new SystemPremierLeagueApiClock();
        $this->sleeper = $sleeper ?? new SleepPremierLeagueApiSleeper();
        $this->throttler = $throttler ?? new FootballDataRequestThrottler(
            $this->clock,
            $this->sleeper,
            (int) config('zcout_premier_league.api.minimum_request_interval_seconds', 7),
            (int) config('zcout_premier_league.api.max_requests_per_minute', 9),
            (int) config('zcout_premier_league.api.rate_limit_window_seconds', 60),
        );
    }

    /**
     * @return list<array{external_id: int, name: string}>
     */
    public function fetchCompetitionTeams(): array
    {
        $response = $this->get('/competitions/PL/teams');

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch PL teams: HTTP '.$response->status());
        }

        $teams = $response->json('teams', []);
        if (! is_array($teams)) {
            throw new RuntimeException('PL teams payload is not an array.');
        }

        $normalized = [];
        foreach ($teams as $team) {
            if (! is_array($team)) {
                continue;
            }

            $normalized[] = [
                'external_id' => (int) ($team['id'] ?? 0),
                'name' => trim((string) ($team['name'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{external_id: int, name: string, date_of_birth: ?string, position: ?string, nationality: ?string, shirt_number: ?int}>
     */
    public function fetchTeamSquad(int $teamExternalId): array
    {
        $response = $this->get('/teams/'.$teamExternalId);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch squad for team {$teamExternalId}: HTTP ".$response->status()
            );
        }

        $squad = $response->json('squad', []);
        if (! is_array($squad)) {
            throw new RuntimeException("Squad payload for team {$teamExternalId} is not an array.");
        }

        $normalized = [];
        foreach ($squad as $player) {
            if (! is_array($player)) {
                continue;
            }

            $normalized[] = [
                'external_id' => (int) ($player['id'] ?? 0),
                'name' => trim((string) ($player['name'] ?? '')),
                'date_of_birth' => isset($player['dateOfBirth']) ? (string) $player['dateOfBirth'] : null,
                'position' => isset($player['position']) ? (string) $player['position'] : null,
                'nationality' => isset($player['nationality']) ? (string) $player['nationality'] : null,
                'shirt_number' => $this->parseShirtNumber($player['shirtNumber'] ?? null),
            ];
        }

        return $normalized;
    }

    public function throttler(): FootballDataRequestThrottler
    {
        return $this->throttler;
    }

    public function sleeper(): PremierLeagueApiSleeper
    {
        return $this->sleeper;
    }

    private function get(string $path): Response
    {
        $url = $this->baseUrl().$path;
        $maxRetries = (int) config('zcout_premier_league.api.max_rate_limit_retries', 3);
        $marginSeconds = (int) config('zcout_premier_league.api.rate_limit_retry_margin_seconds', 2);
        $fallbackWaitSeconds = (int) config('zcout_premier_league.api.rate_limit_fallback_wait_seconds', 60);
        $rateLimitAttempts = 0;

        while (true) {
            $this->throttler->waitBeforeNextRequest();

            $response = Http::withHeaders($this->authHeaders())->get($url);
            $this->throttler->recordRequest();

            if ($response->status() !== 429) {
                return $response;
            }

            if ($rateLimitAttempts >= $maxRetries) {
                throw new RuntimeException(
                    'Football-data.org rate limit exceeded after '.$maxRetries.' retries: '.$response->body()
                );
            }

            $waitSeconds = FootballDataRateLimitWaitParser::parseSeconds($response, $fallbackWaitSeconds) + $marginSeconds;
            $this->sleeper->sleep($waitSeconds);
            $rateLimitAttempts++;
        }
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->token ?? env('FOOTBALL_DATA_TOKEN');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Missing FOOTBALL_DATA_TOKEN in .env');
        }

        return ['X-Auth-Token' => $token];
    }

    private function parseShirtNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\d+/', $value, $matches)) {
            $number = (int) $matches[0];

            return $number > 0 ? $number : null;
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('zcout_premier_league.api_base', 'https://api.football-data.org/v4'), '/');
    }
}
