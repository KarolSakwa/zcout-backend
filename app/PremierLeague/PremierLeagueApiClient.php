<?php

namespace App\PremierLeague;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PremierLeagueApiClient
{
    public function __construct(
        private readonly ?string $token = null,
    ) {
    }

    /**
     * @return list<array{external_id: int, name: string}>
     */
    public function fetchCompetitionTeams(): array
    {
        $response = $this->http()
            ->get($this->baseUrl().'/competitions/PL/teams');

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
        $response = $this->http()
            ->get($this->baseUrl().'/teams/'.$teamExternalId);

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

    private function http()
    {
        $token = $this->token ?? env('FOOTBALL_DATA_TOKEN');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Missing FOOTBALL_DATA_TOKEN in .env');
        }

        return Http::withHeaders(['X-Auth-Token' => $token])
            ->retry(
                (int) config('zcout_premier_league.api_retry_times', 3),
                (int) config('zcout_premier_league.api_retry_sleep_ms', 800),
            );
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('zcout_premier_league.api_base', 'https://api.football-data.org/v4'), '/');
    }
}
