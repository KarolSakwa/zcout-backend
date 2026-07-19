<?php

namespace App\PremierLeague;

use App\Models\Club;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PremierLeagueSeasonSynchronizer
{
    public function __construct(
        private readonly PremierLeagueApiClient $api,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $activeClubs = Club::query()->currentPremierLeague()->orderBy('id')->get(['id', 'name', 'slug', 'external_id']);
        $activeCount = $activeClubs->count();
        $expected = (int) config('zcout_premier_league.expected_club_count', 20);

        $playersOutsideActiveClub = Player::query()
            ->whereNotNull('club_id')
            ->whereDoesntHave('clubRel', fn ($q) => $q->currentPremierLeague())
            ->count();

        $activePlayersWithInactiveClub = Player::query()
            ->inCurrentPremierLeague()
            ->whereHas('clubRel', fn ($q) => $q->where('is_current_premier_league', false))
            ->count();

        $invalidLocks = $this->findInvalidActiveLocks();

        return [
            'active_club_count' => $activeCount,
            'expected_club_count' => $expected,
            'active_clubs_ok' => $activeCount === $expected,
            'active_club_names' => $activeClubs->pluck('name')->values()->all(),
            'players_in_current_premier_league' => Player::query()->inCurrentPremierLeague()->count(),
            'players_with_club_outside_current_pl' => $playersOutsideActiveClub,
            'players_in_scope_with_inactive_club' => $activePlayersWithInactiveClub,
            'invalid_active_locks' => count($invalidLocks),
            'votes_count' => (int) DB::table('votes')->count(),
            'duels_count' => (int) DB::table('duels')->count(),
            'ratings_count' => (int) DB::table('player_attribute_ratings')->count(),
            'overalls_count' => (int) DB::table('player_overalls')->count(),
            'players_count' => (int) DB::table('players')->count(),
            'clubs_count' => (int) DB::table('clubs')->count(),
            'projection_rebuild_required' => true,
        ];
    }

    public function sync(
        bool $dryRun = false,
        bool $detachMissingPlayers = false,
        int $sleepSeconds = 0,
    ): PremierLeagueSyncReport {
        $historyBefore = $this->historyCounts();

        try {
            $dataset = $this->fetchDataset($sleepSeconds);
        } catch (Throwable $e) {
            return new PremierLeagueSyncReport(
                success: false,
                dryRun: $dryRun,
                errors: [$e->getMessage()],
            );
        }

        $plan = $this->buildPlan($dataset, $detachMissingPlayers);

        if ($plan['errors'] !== []) {
            return new PremierLeagueSyncReport(
                success: false,
                dryRun: $dryRun,
                errors: $plan['errors'],
                warnings: $plan['warnings'],
                clubLines: $plan['club_lines'],
                playerLines: $plan['player_lines'],
                lockLines: $plan['lock_lines'],
                counts: $plan['counts'],
            );
        }

        if ($dryRun) {
            return new PremierLeagueSyncReport(
                success: true,
                dryRun: true,
                warnings: $plan['warnings'],
                clubLines: $plan['club_lines'],
                playerLines: $plan['player_lines'],
                lockLines: $plan['lock_lines'],
                counts: $plan['counts'],
                verify: ['history_before' => $historyBefore],
                applied: false,
            );
        }

        try {
            DB::transaction(function () use ($plan, $detachMissingPlayers) {
                $this->applyClubChanges($plan['club_ops']);
                $this->applyPlayerChanges($plan['player_ops'], $detachMissingPlayers);
                $this->clearInvalidLocks($plan['invalid_lock_ids']);
            });
        } catch (Throwable $e) {
            return new PremierLeagueSyncReport(
                success: false,
                dryRun: false,
                errors: ['Transaction rolled back: '.$e->getMessage()],
                warnings: $plan['warnings'],
                clubLines: $plan['club_lines'],
                playerLines: $plan['player_lines'],
                lockLines: $plan['lock_lines'],
                counts: $plan['counts'],
            );
        }

        $historyAfter = $this->historyCounts();
        $verify = $this->verify();
        $verify['history_before'] = $historyBefore;
        $verify['history_after'] = $historyAfter;
        $verify['history_not_decreased'] = $this->historyNotDecreased($historyBefore, $historyAfter);

        $errors = [];
        if (! $verify['active_clubs_ok']) {
            $errors[] = "Post-sync invariant failed: expected {$verify['expected_club_count']} active clubs, got {$verify['active_club_count']}.";
        }
        if ($verify['invalid_active_locks'] > 0) {
            $errors[] = "Post-sync invariant failed: {$verify['invalid_active_locks']} invalid active locks remain.";
        }
        if (! $verify['history_not_decreased']) {
            $errors[] = 'Post-sync invariant failed: historical row counts decreased.';
        }

        return new PremierLeagueSyncReport(
            success: $errors === [],
            dryRun: false,
            errors: $errors,
            warnings: $plan['warnings'],
            clubLines: $plan['club_lines'],
            playerLines: $plan['player_lines'],
            lockLines: $plan['lock_lines'],
            counts: $plan['counts'],
            verify: $verify,
            applied: true,
        );
    }

    /**
     * @return array{teams: list<array{external_id: int, name: string}>, squads: array<int, list<array<string, mixed>>>}
     */
    private function fetchDataset(int $sleepSeconds): array
    {
        $teams = $this->api->fetchCompetitionTeams();
        $expected = (int) config('zcout_premier_league.expected_club_count', 20);

        if (count($teams) !== $expected) {
            throw new \RuntimeException(
                'API returned '.count($teams)." clubs; expected exactly {$expected}. Aborting without DB changes."
            );
        }

        $seenExt = [];
        foreach ($teams as $team) {
            $ext = (int) $team['external_id'];
            $name = (string) $team['name'];

            if ($ext <= 0) {
                throw new \RuntimeException('API returned a club with empty/invalid external_id. Aborting.');
            }
            if ($name === '') {
                throw new \RuntimeException("API returned club external_id={$ext} with empty name. Aborting.");
            }
            if (isset($seenExt[$ext])) {
                throw new \RuntimeException("API returned duplicate club external_id={$ext}. Aborting.");
            }
            $seenExt[$ext] = true;
        }

        $squads = [];
        foreach ($teams as $index => $team) {
            $ext = (int) $team['external_id'];
            $squads[$ext] = $this->api->fetchTeamSquad($ext);

            if ($sleepSeconds > 0 && $index < count($teams) - 1) {
                sleep($sleepSeconds);
            }
        }

        return [
            'teams' => $teams,
            'squads' => $squads,
        ];
    }

    /**
     * @param  array{teams: list<array{external_id: int, name: string}>, squads: array<int, list<array<string, mixed>>>}  $dataset
     * @return array<string, mixed>
     */
    private function buildPlan(array $dataset, bool $detachMissingPlayers): array
    {
        $errors = [];
        $warnings = [];
        $clubLines = [];
        $playerLines = [];
        $clubOps = [];
        $playerOps = [];

        $teams = $dataset['teams'];
        $squads = $dataset['squads'];
        $apiClubExtIds = array_map(fn ($t) => (int) $t['external_id'], $teams);

        $existingClubs = DB::table('clubs')->get()->keyBy('id');
        $clubsByExternalId = [];
        foreach ($existingClubs as $club) {
            if ($club->external_id !== null) {
                $ext = (int) $club->external_id;
                if (isset($clubsByExternalId[$ext])) {
                    $errors[] = "DB conflict: multiple clubs share external_id={$ext}.";
                }
                $clubsByExternalId[$ext] = $club;
            }
        }

        $clubsByName = [];
        $clubsBySlug = [];
        foreach ($existingClubs as $club) {
            $clubsByName[mb_strtolower((string) $club->name)][] = $club;
            $clubsBySlug[mb_strtolower((string) $club->slug)][] = $club;
        }

        $activeExternalIds = [];

        foreach ($teams as $team) {
            $ext = (int) $team['external_id'];
            $name = (string) $team['name'];
            $slug = Str::slug($name);
            $activeExternalIds[$ext] = true;

            $existing = $clubsByExternalId[$ext] ?? null;

            if ($existing) {
                $nameConflict = $clubsByName[mb_strtolower($name)] ?? [];
                foreach ($nameConflict as $other) {
                    if ((int) $other->id !== (int) $existing->id && (int) ($other->external_id ?? 0) !== $ext) {
                        $errors[] = "Club name conflict: '{$name}' matches club #{$other->id} (external_id={$other->external_id}) while API external_id={$ext} maps to club #{$existing->id}. Refusing automatic merge.";
                    }
                }

                $slugConflict = $clubsBySlug[mb_strtolower($slug)] ?? [];
                foreach ($slugConflict as $other) {
                    if ((int) $other->id !== (int) $existing->id) {
                        $errors[] = "Club slug conflict: '{$slug}' already used by club #{$other->id}. Refusing update for external_id={$ext}.";
                    }
                }

                $changes = [];
                if ((string) $existing->name !== $name) {
                    $changes['name'] = ['from' => $existing->name, 'to' => $name];
                }
                if ((string) $existing->slug !== $slug) {
                    $changes['slug'] = ['from' => $existing->slug, 'to' => $slug];
                }
                $wasActive = (bool) $existing->is_current_premier_league;
                if (! $wasActive) {
                    $changes['is_current_premier_league'] = ['from' => false, 'to' => true];
                }

                $clubOps[] = [
                    'action' => 'update',
                    'club_id' => (int) $existing->id,
                    'external_id' => $ext,
                    'name' => $name,
                    'slug' => $slug,
                    'set_active' => true,
                ];

                $clubLines[] = [
                    'action' => $changes === [] && $wasActive ? 'reuse' : 'update',
                    'club_id' => (int) $existing->id,
                    'external_id' => $ext,
                    'name' => $name,
                    'changes' => $changes,
                    'internal_clubs.id_preserved' => true,
                ];
            } else {
                $nameMatches = $clubsByName[mb_strtolower($name)] ?? [];
                if ($nameMatches !== []) {
                    $ids = collect($nameMatches)->map(fn ($c) => "#{$c->id}/ext=".($c->external_id ?? 'null'))->implode(', ');
                    $errors[] = "Club create blocked: name '{$name}' (API external_id={$ext}) matches existing club(s) {$ids} with different external_id. Refusing automatic merge.";
                    continue;
                }

                $slugMatches = $clubsBySlug[mb_strtolower($slug)] ?? [];
                if ($slugMatches !== []) {
                    $ids = collect($slugMatches)->map(fn ($c) => "#{$c->id}")->implode(', ');
                    $errors[] = "Club create blocked: slug '{$slug}' already used by {$ids}. Refusing create for API external_id={$ext}.";
                    continue;
                }

                $clubOps[] = [
                    'action' => 'create',
                    'club_id' => null,
                    'external_id' => $ext,
                    'name' => $name,
                    'slug' => $slug,
                    'set_active' => true,
                ];

                $clubLines[] = [
                    'action' => 'create',
                    'club_id' => null,
                    'external_id' => $ext,
                    'name' => $name,
                    'internal_clubs.id_preserved' => 'n/a (new)',
                ];
            }
        }

        foreach ($existingClubs as $club) {
            $ext = $club->external_id !== null ? (int) $club->external_id : null;
            $stillActive = $ext !== null && isset($activeExternalIds[$ext]);

            if (! $stillActive && (bool) $club->is_current_premier_league) {
                $clubOps[] = [
                    'action' => 'deactivate',
                    'club_id' => (int) $club->id,
                    'external_id' => $ext,
                    'name' => (string) $club->name,
                    'set_active' => false,
                ];
                $clubLines[] = [
                    'action' => 'deactivate',
                    'club_id' => (int) $club->id,
                    'external_id' => $ext,
                    'name' => (string) $club->name,
                    'is_current_premier_league' => ['from' => true, 'to' => false],
                    'internal_clubs.id_preserved' => true,
                ];
            } elseif (! $stillActive) {
                $clubLines[] = [
                    'action' => 'keep_inactive',
                    'club_id' => (int) $club->id,
                    'external_id' => $ext,
                    'name' => (string) $club->name,
                    'internal_clubs.id_preserved' => true,
                ];
            }
        }

        // Squad validation across all teams
        $apiPlayersByExt = [];
        foreach ($squads as $clubExtId => $squad) {
            foreach ($squad as $player) {
                $playerExt = (int) ($player['external_id'] ?? 0);
                $playerName = trim((string) ($player['name'] ?? ''));

                if ($playerExt <= 0) {
                    $errors[] = "Squad for club external_id={$clubExtId} contains player with empty external_id.";
                    continue;
                }
                if ($playerName === '') {
                    $errors[] = "Squad for club external_id={$clubExtId} contains player external_id={$playerExt} with empty name.";
                    continue;
                }
                if (isset($apiPlayersByExt[$playerExt])) {
                    $errors[] = "Player external_id={$playerExt} appears in multiple squads (clubs {$apiPlayersByExt[$playerExt]['club_external_id']} and {$clubExtId}). Aborting.";
                    continue;
                }

                $apiPlayersByExt[$playerExt] = [
                    'external_id' => $playerExt,
                    'name' => $playerName,
                    'club_external_id' => (int) $clubExtId,
                    'date_of_birth' => $player['date_of_birth'] ?? null,
                    'position' => $player['position'] ?? null,
                    'nationality' => $player['nationality'] ?? null,
                    'shirt_number' => $player['shirt_number'] ?? null,
                ];
            }
        }

        $posIdByLabel = [];
        foreach (DB::table('positions')->select('id', 'label')->get() as $row) {
            $k = $this->norm($row->label);
            if ($k) {
                $posIdByLabel[$k] = (int) $row->id;
            }
        }

        $countryIdByCode = DB::table('countries')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        $remapResolution = $this->resolvePlayerExternalIdRemaps();
        $errors = array_merge($errors, $remapResolution['errors']);
        $remapOldToNew = $remapResolution['oldToNew'];
        $remapNewToOld = $remapResolution['newToOld'];

        $existingPlayers = DB::table('players')->get();
        $playersByExternalId = [];
        foreach ($existingPlayers as $player) {
            if ($player->external_id !== null) {
                $ext = (int) $player->external_id;
                if (isset($playersByExternalId[$ext])) {
                    $errors[] = "DB conflict: multiple players share external_id={$ext}. Refusing sync.";
                }
                $playersByExternalId[$ext] = $player;
            }
        }

        $playersByNormName = [];
        foreach ($existingPlayers as $player) {
            $playersByNormName[$this->norm((string) $player->name)][] = $player;
        }

        $apiPlayerExtSet = [];
        $remappedPlayerIds = [];
        foreach ($apiPlayersByExt as $playerExt => $apiPlayer) {
            $apiPlayerExtSet[$playerExt] = true;
            $clubExt = (int) $apiPlayer['club_external_id'];

            // Resolve target club_id after ops: prefer existing by external, else pending create marker
            $targetClub = $clubsByExternalId[$clubExt] ?? null;
            $targetClubId = $targetClub ? (int) $targetClub->id : null;
            $targetClubName = $targetClub?->name;
            foreach ($teams as $team) {
                if ((int) $team['external_id'] === $clubExt) {
                    $targetClubName = $team['name'];
                    break;
                }
            }

            $existing = $playersByExternalId[$playerExt] ?? null;
            $isExternalIdRemap = false;
            $fromExternalId = null;

            if ($existing === null && isset($remapNewToOld[$playerExt])) {
                $fromExternalId = $remapNewToOld[$playerExt];
                $existing = $playersByExternalId[$fromExternalId] ?? null;

                if ($existing === null) {
                    $errors[] = "player_external_id_remaps: API external_id={$playerExt} maps from {$fromExternalId} but no DB player has that old external_id.";
                    continue;
                }

                foreach ($existingPlayers as $candidate) {
                    if ($candidate->external_id !== null
                        && (int) $candidate->external_id === $playerExt
                        && (int) $candidate->id !== (int) $existing->id) {
                        $errors[] = "Remap conflict: external_id {$playerExt} already belongs to player #{$candidate->id} while remap targets player #{$existing->id} (old external_id {$fromExternalId}).";
                        $existing = null;
                        break;
                    }
                }

                if ($existing !== null) {
                    $isExternalIdRemap = true;
                    $remappedPlayerIds[(int) $existing->id] = true;
                }
            } elseif ($existing !== null && isset($remapNewToOld[$playerExt])) {
                $fromExternalId = $remapNewToOld[$playerExt];
                $oldPlayer = $playersByExternalId[$fromExternalId] ?? null;
                if ($oldPlayer !== null && (int) $oldPlayer->id !== (int) $existing->id) {
                    $errors[] = "Remap conflict: both external_id {$fromExternalId} (player #{$oldPlayer->id}) and {$playerExt} (player #{$existing->id}) exist while remap maps {$fromExternalId} → {$playerExt}.";
                }
            }

            $positionId = null;
            if (! empty($apiPlayer['position'])) {
                $positionId = $posIdByLabel[$this->norm((string) $apiPlayer['position'])] ?? null;
            }

            $countryId = null;
            $nationality = $apiPlayer['nationality'] ?? null;
            $countryCreateCode = null;
            if (is_string($nationality) && trim($nationality) !== '') {
                $code = strtoupper(trim($nationality));
                if (isset($countryIdByCode[$code])) {
                    $countryId = $countryIdByCode[$code];
                } else {
                    $countryCreateCode = $code;
                }
            }

            if ($existing) {
                $fromClubId = $existing->club_id !== null ? (int) $existing->club_id : null;
                $resolvedTargetClubId = $targetClubId;
                // For newly created clubs, club_id resolved at apply time via external_id
                $dob = $apiPlayer['date_of_birth'] ?: $existing->date_of_birth;
                $resolvedCountryId = $countryId ?: ($existing->country_id !== null ? (int) $existing->country_id : null);

                // Provider layer only — never touch name/slug/position_id/number/manual_*.
                $apiName = trim((string) ($apiPlayer['name'] ?? ''));
                $fdName = $apiName !== ''
                    ? $apiName
                    : ($existing->fd_name !== null ? (string) $existing->fd_name : null);
                $fdPositionId = $positionId
                    ?? ($existing->fd_position_id !== null ? (int) $existing->fd_position_id : null);
                $apiShirtNumber = $apiPlayer['shirt_number'] ?? null;
                $fdNumber = is_int($apiShirtNumber) && $apiShirtNumber > 0
                    ? $apiShirtNumber
                    : null;

                $playerOps[] = [
                    'action' => $isExternalIdRemap ? 'external_id_remap' : 'update',
                    'player_id' => (int) $existing->id,
                    'external_id' => $playerExt,
                    'previous_external_id' => $fromExternalId,
                    'fd_name' => $fdName,
                    'fd_position_id' => $fdPositionId,
                    'fd_number' => $fdNumber,
                    'update_fd_number' => $fdNumber !== null,
                    'club_external_id' => $clubExt,
                    'club_id' => $resolvedTargetClubId,
                    'club_name' => $targetClubName,
                    'date_of_birth' => $dob,
                    'country_id' => $resolvedCountryId,
                    'country_create_code' => $countryCreateCode,
                    'from_club_id' => $fromClubId,
                ];

                $lineAction = $isExternalIdRemap
                    ? 'external_id_remap'
                    : (($fromClubId !== $resolvedTargetClubId && $resolvedTargetClubId !== null) || ($fromClubId !== null && $targetClubId === null && ! isset($clubsByExternalId[$clubExt]))
                        ? 'transfer_or_update'
                        : 'update');

                $playerLines[] = [
                    'action' => $lineAction,
                    'player_id' => (int) $existing->id,
                    'external_id' => $isExternalIdRemap
                        ? ['from' => $fromExternalId, 'to' => $playerExt]
                        : $playerExt,
                    'name' => $fdName ?? (string) $existing->name,
                    'fd_name' => $fdName,
                    'club_id' => ['from' => $fromClubId, 'to' => $resolvedTargetClubId ?? "new_club:ext:{$clubExt}"],
                    'internal_players.id_preserved' => true,
                    'ratings_preserved' => true,
                    'votes_preserved' => true,
                    'manual_overrides_preserved' => true,
                ];
            } else {
                $nameKey = $this->norm((string) $apiPlayer['name']);
                $nameMatches = $playersByNormName[$nameKey] ?? [];
                $ambiguous = [];
                foreach ($nameMatches as $match) {
                    if ($match->external_id === null || (int) $match->external_id !== $playerExt) {
                        $ambiguous[] = $match;
                    }
                }

                if ($ambiguous !== []) {
                    $detail = collect($ambiguous)->map(function ($p) {
                        return "#{$p->id} ext=".($p->external_id ?? 'null')." club_id=".($p->club_id ?? 'null');
                    })->implode('; ');
                    $errors[] = "Player create blocked for API external_id={$playerExt} name='{$apiPlayer['name']}': potential duplicate(s) {$detail}. Refusing name-based merge.";
                    $warnings[] = "Diagnostic name match for new player '{$apiPlayer['name']}' (ext={$playerExt}): {$detail}";
                    continue;
                }

                $apiName = trim((string) ($apiPlayer['name'] ?? ''));
                $createName = $apiName !== '' ? $apiName : 'Unknown Player';
                $apiShirtNumber = $apiPlayer['shirt_number'] ?? null;
                $fdNumber = is_int($apiShirtNumber) && $apiShirtNumber > 0
                    ? $apiShirtNumber
                    : null;

                $playerOps[] = [
                    'action' => 'create',
                    'player_id' => null,
                    'external_id' => $playerExt,
                    'name' => $createName,
                    'slug' => $this->uniquePlayerSlug($createName, null),
                    'fd_name' => $apiName !== '' ? $apiName : null,
                    'fd_position_id' => $positionId,
                    'fd_number' => $fdNumber,
                    'club_external_id' => $clubExt,
                    'club_id' => $targetClubId,
                    'club_name' => $targetClubName,
                    'date_of_birth' => $apiPlayer['date_of_birth'],
                    'position_id' => $positionId,
                    'country_id' => $countryId,
                    'country_create_code' => $countryCreateCode,
                    'from_club_id' => null,
                ];

                $playerLines[] = [
                    'action' => 'create',
                    'player_id' => null,
                    'external_id' => $playerExt,
                    'name' => $createName,
                    'club_external_id' => $clubExt,
                    'internal_players.id_preserved' => 'n/a (new)',
                ];
            }
        }

        // Missing from API squads
        foreach ($existingPlayers as $player) {
            if ($player->external_id === null) {
                $warnings[] = "Player #{$player->id} '{$player->name}' has null external_id; left untouched.";
                continue;
            }

            $ext = (int) $player->external_id;
            if (isset($apiPlayerExtSet[$ext])) {
                continue;
            }

            if (isset($remapOldToNew[$ext]) && isset($apiPlayerExtSet[$remapOldToNew[$ext]])) {
                continue;
            }

            if (isset($remappedPlayerIds[(int) $player->id])) {
                continue;
            }

            if ($player->club_id === null) {
                continue;
            }

            $club = $existingClubs->get($player->club_id);
            $clubStillInApi = $club && $club->external_id !== null && isset($activeExternalIds[(int) $club->external_id]);

            if ($clubStillInApi) {
                $playerLines[] = [
                    'action' => $detachMissingPlayers ? 'detach' : 'missing_from_api_squad',
                    'player_id' => (int) $player->id,
                    'external_id' => $ext,
                    'name' => (string) $player->name,
                    'club_id' => [
                        'from' => (int) $player->club_id,
                        'to' => $detachMissingPlayers ? null : (int) $player->club_id,
                    ],
                    'internal_players.id_preserved' => true,
                    'ratings_preserved' => true,
                    'note' => $detachMissingPlayers
                        ? 'Would set club_id=null because player missing from all current API squads.'
                        : 'Reported only; club_id unchanged without --detach-missing-players.',
                ];

                if ($detachMissingPlayers) {
                    $playerOps[] = [
                        'action' => 'detach',
                        'player_id' => (int) $player->id,
                        'external_id' => $ext,
                        'name' => (string) $player->name,
                        'club_id' => null,
                        'from_club_id' => (int) $player->club_id,
                    ];
                } else {
                    $warnings[] = "Player #{$player->id} '{$player->name}' missing from current API squads but club remains in PL; club_id kept.";
                }
            } else {
                $playerLines[] = [
                    'action' => 'remain_with_relegated_or_inactive_club',
                    'player_id' => (int) $player->id,
                    'external_id' => $ext,
                    'name' => (string) $player->name,
                    'club_id' => (int) $player->club_id,
                    'internal_players.id_preserved' => true,
                    'ratings_preserved' => true,
                    'note' => 'club_id kept; club will be outside current Premier League pool.',
                ];
            }
        }

        // Recompute invalid locks after plan conceptually: use club activity from plan
        $plannedActiveClubIds = [];
        foreach ($clubOps as $op) {
            if (($op['set_active'] ?? false) === true && isset($op['club_id']) && $op['club_id']) {
                $plannedActiveClubIds[(int) $op['club_id']] = true;
            }
            if (($op['action'] ?? '') === 'create') {
                // new clubs become active; id unknown until apply — locks only reference existing players
            }
        }
        foreach ($existingClubs as $club) {
            $ext = $club->external_id !== null ? (int) $club->external_id : null;
            if ($ext !== null && isset($activeExternalIds[$ext])) {
                $plannedActiveClubIds[(int) $club->id] = true;
            }
        }

        $lockLines = [];
        $invalidLockIds = [];
        $locks = DB::table('voter_duel_locks as l')
            ->join('duels as d', 'd.id', '=', 'l.duel_id')
            ->select('l.id as lock_id', 'l.voter_hash', 'l.duel_id', 'd.player_a_id', 'd.player_b_id')
            ->get();

        $playerClubMap = $existingPlayers->keyBy('id');
        foreach ($locks as $lock) {
            $invalid = false;
            $diag = [];
            foreach ([(int) $lock->player_a_id, (int) $lock->player_b_id] as $pid) {
                $p = $playerClubMap->get($pid);
                if (! $p) {
                    $invalid = true;
                    $diag[] = "missing_player:{$pid}";
                    continue;
                }

                // After sync, player is in pool if their post-sync club is active.
                $postClubId = $p->club_id !== null ? (int) $p->club_id : null;
                $postExt = $p->external_id !== null ? (int) $p->external_id : null;

                if ($postExt !== null && isset($apiPlayerExtSet[$postExt])) {
                    $apiClubExt = (int) $apiPlayersByExt[$postExt]['club_external_id'];
                    $mappedClub = $clubsByExternalId[$apiClubExt] ?? null;
                    if ($mappedClub) {
                        $postClubId = (int) $mappedClub->id;
                    }
                } elseif ($detachMissingPlayers && $postClubId !== null) {
                    $club = $existingClubs->get($postClubId);
                    $clubStillInApi = $club && $club->external_id !== null && isset($activeExternalIds[(int) $club->external_id]);
                    if ($clubStillInApi && ($postExt === null || ! isset($apiPlayerExtSet[$postExt]))) {
                        $postClubId = null;
                    }
                }

                $inPool = $postClubId !== null && isset($plannedActiveClubIds[$postClubId]);
                if (! $inPool) {
                    $invalid = true;
                    $diag[] = "player:{$pid}/club_id:".($postClubId ?? 'null');
                }
            }

            if ($invalid) {
                $invalidLockIds[] = (int) $lock->lock_id;
                $lockLines[] = [
                    'lock_id' => (int) $lock->lock_id,
                    'duel_id' => (int) $lock->duel_id,
                    'voter_hash' => (string) $lock->voter_hash,
                    'player_a_id' => (int) $lock->player_a_id,
                    'player_b_id' => (int) $lock->player_b_id,
                    'reason' => implode(', ', $diag),
                    'action' => 'delete_lock',
                ];
            }
        }

        $counts = [
            'api_clubs' => count($teams),
            'api_players' => count($apiPlayersByExt),
            'clubs_create' => count(array_filter($clubOps, fn ($o) => $o['action'] === 'create')),
            'clubs_update' => count(array_filter($clubOps, fn ($o) => $o['action'] === 'update')),
            'clubs_deactivate' => count(array_filter($clubOps, fn ($o) => $o['action'] === 'deactivate')),
            'players_create' => count(array_filter($playerOps, fn ($o) => $o['action'] === 'create')),
            'players_update' => count(array_filter($playerOps, fn ($o) => $o['action'] === 'update')),
            'players_external_id_remap' => count(array_filter($playerOps, fn ($o) => $o['action'] === 'external_id_remap')),
            'players_detach' => count(array_filter($playerOps, fn ($o) => $o['action'] === 'detach')),
            'invalid_locks' => count($invalidLockIds),
        ];

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'club_lines' => $clubLines,
            'player_lines' => $playerLines,
            'lock_lines' => $lockLines,
            'club_ops' => $clubOps,
            'player_ops' => $playerOps,
            'invalid_lock_ids' => $invalidLockIds,
            'counts' => $counts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $clubOps
     */
    private function applyClubChanges(array $clubOps): void
    {
        $now = now();

        foreach ($clubOps as $op) {
            if ($op['action'] === 'create') {
                DB::table('clubs')->insert([
                    'external_id' => $op['external_id'],
                    'name' => $op['name'],
                    'slug' => $op['slug'],
                    'is_current_premier_league' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            if ($op['action'] === 'update') {
                DB::table('clubs')->where('id', $op['club_id'])->update([
                    'name' => $op['name'],
                    'slug' => $op['slug'],
                    'is_current_premier_league' => true,
                    'updated_at' => $now,
                ]);
                continue;
            }

            if ($op['action'] === 'deactivate') {
                DB::table('clubs')->where('id', $op['club_id'])->update([
                    'is_current_premier_league' => false,
                    'updated_at' => $now,
                ]);
            }
        }

        // Ensure only API clubs are active (belt and suspenders)
        $activeExtIds = [];
        foreach ($clubOps as $op) {
            if (($op['set_active'] ?? false) === true) {
                $activeExtIds[] = (int) $op['external_id'];
            }
        }
        $activeExtIds = array_values(array_unique($activeExtIds));

        if ($activeExtIds !== []) {
            DB::table('clubs')
                ->whereIn('external_id', $activeExtIds)
                ->update(['is_current_premier_league' => true, 'updated_at' => $now]);

            DB::table('clubs')
                ->where(function ($q) use ($activeExtIds) {
                    $q->whereNull('external_id')
                        ->orWhereNotIn('external_id', $activeExtIds);
                })
                ->update(['is_current_premier_league' => false, 'updated_at' => $now]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $playerOps
     */
    private function applyPlayerChanges(array $playerOps, bool $detachMissingPlayers): void
    {
        $clubIdByExternalId = DB::table('clubs')
            ->whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $countryIdByCode = DB::table('countries')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        foreach ($playerOps as $op) {
            if (! empty($op['country_create_code'])) {
                $code = (string) $op['country_create_code'];
                if (! isset($countryIdByCode[$code])) {
                    DB::table('countries')->updateOrInsert(
                        ['code' => $code],
                        [
                            'name' => $code,
                            'flag_url' => '/flags/'.strtolower($code).'.png',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                    $countryIdByCode[$code] = (int) DB::table('countries')->where('code', $code)->value('id');
                }
                $op['country_id'] = $countryIdByCode[$code];
            }

            if ($op['action'] === 'detach') {
                if ($detachMissingPlayers) {
                    DB::table('players')->where('id', $op['player_id'])->update([
                        'club_id' => null,
                        'club' => null,
                    ]);
                }
                continue;
            }

            $clubExt = (int) $op['club_external_id'];
            $clubId = $clubIdByExternalId[$clubExt] ?? null;
            if ($clubId === null) {
                throw new \RuntimeException("Missing club for player external_id={$op['external_id']} club_external_id={$clubExt}");
            }

            $clubName = (string) (DB::table('clubs')->where('id', $clubId)->value('name') ?? $op['club_name'] ?? '');

            if ($op['action'] === 'create') {
                DB::table('players')->insert([
                    'external_id' => $op['external_id'],
                    'name' => $op['name'],
                    'slug' => $op['slug'],
                    'fd_name' => $op['fd_name'] ?? null,
                    'fd_number' => $op['fd_number'] ?? null,
                    'fd_position_id' => $op['fd_position_id'] ?? null,
                    'fd_synced_at' => now(),
                    'club_id' => $clubId,
                    'club' => $clubName,
                    'date_of_birth' => $op['date_of_birth'],
                    'country_id' => $op['country_id'],
                    'position_id' => $op['position_id'],
                ]);
                continue;
            }

            if ($op['action'] === 'update' || $op['action'] === 'external_id_remap') {
                // Provider/raw fields + membership only. Never name/slug/position_id/number/manual_*.
                $update = [
                    'fd_name' => $op['fd_name'],
                    'fd_position_id' => $op['fd_position_id'],
                    'fd_synced_at' => now(),
                    'club_id' => $clubId,
                    'club' => $clubName,
                    'date_of_birth' => $op['date_of_birth'],
                    'country_id' => $op['country_id'],
                ];

                if ($op['action'] === 'external_id_remap') {
                    $update['external_id'] = $op['external_id'];
                }

                if (($op['update_fd_number'] ?? false) === true && $op['fd_number'] !== null) {
                    $update['fd_number'] = $op['fd_number'];
                }

                DB::table('players')->where('id', $op['player_id'])->update($update);
            }
        }
    }

    /**
     * @return array{oldToNew: array<int, int>, newToOld: array<int, int>, errors: list<string>}
     */
    private function resolvePlayerExternalIdRemaps(): array
    {
        $configured = config('zcout_premier_league.player_external_id_remaps', []);
        $errors = [];
        $oldToNew = [];
        $newToOld = [];

        if (! is_array($configured)) {
            return [
                'oldToNew' => [],
                'newToOld' => [],
                'errors' => ['player_external_id_remaps must be an array.'],
            ];
        }

        foreach ($configured as $oldExternalId => $newExternalId) {
            $oldId = (int) $oldExternalId;
            $newId = (int) $newExternalId;

            if ($oldId <= 0 || $newId <= 0) {
                $errors[] = "Invalid player_external_id_remaps entry: {$oldExternalId} => {$newExternalId}.";
                continue;
            }

            if ($oldId === $newId) {
                $errors[] = "Invalid player_external_id_remaps: external_id {$oldId} maps to itself.";
                continue;
            }

            if (isset($newToOld[$newId]) && $newToOld[$newId] !== $oldId) {
                $errors[] = "Invalid player_external_id_remaps: new external_id {$newId} is mapped from both {$newToOld[$newId]} and {$oldId}.";
            }

            $oldToNew[$oldId] = $newId;
            $newToOld[$newId] = $oldId;
        }

        return [
            'oldToNew' => $oldToNew,
            'newToOld' => $newToOld,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<int>  $lockIds
     */
    private function clearInvalidLocks(array $lockIds): void
    {
        if ($lockIds === []) {
            return;
        }

        DB::table('voter_duel_locks')->whereIn('id', $lockIds)->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findInvalidActiveLocks(): array
    {
        $locks = DB::table('voter_duel_locks as l')
            ->join('duels as d', 'd.id', '=', 'l.duel_id')
            ->select('l.id as lock_id', 'l.duel_id', 'd.player_a_id', 'd.player_b_id')
            ->get();

        $invalid = [];
        foreach ($locks as $lock) {
            $aOk = Player::query()->whereKey((int) $lock->player_a_id)->inCurrentPremierLeague()->exists();
            $bOk = Player::query()->whereKey((int) $lock->player_b_id)->inCurrentPremierLeague()->exists();
            if (! $aOk || ! $bOk) {
                $invalid[] = [
                    'lock_id' => (int) $lock->lock_id,
                    'duel_id' => (int) $lock->duel_id,
                    'player_a_id' => (int) $lock->player_a_id,
                    'player_b_id' => (int) $lock->player_b_id,
                ];
            }
        }

        return $invalid;
    }

    private function uniquePlayerSlug(string $name, ?int $ignorePlayerId): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'player';
        }

        $slug = $base;
        $suffix = 2;
        while (
            DB::table('players')
                ->where('slug', $slug)
                ->when($ignorePlayerId !== null, fn ($q) => $q->where('id', '!=', $ignorePlayerId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function norm(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        $s = str_replace(["\u{2019}", "\u{2018}", "'", '`'], "'", $s);
        $s = str_replace(["\u{2013}", "\u{2014}"], '-', $s);

        return mb_strtolower($s);
    }

    /**
     * @return array<string, int>
     */
    private function historyCounts(): array
    {
        return [
            'players' => (int) DB::table('players')->count(),
            'clubs' => (int) DB::table('clubs')->count(),
            'votes' => (int) DB::table('votes')->count(),
            'duels' => (int) DB::table('duels')->count(),
            'ratings' => (int) DB::table('player_attribute_ratings')->count(),
            'overalls' => (int) DB::table('player_overalls')->count(),
        ];
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    private function historyNotDecreased(array $before, array $after): bool
    {
        foreach (['players', 'clubs', 'votes', 'duels', 'ratings', 'overalls'] as $key) {
            if (($after[$key] ?? 0) < ($before[$key] ?? 0)) {
                return false;
            }
        }

        return true;
    }
}
