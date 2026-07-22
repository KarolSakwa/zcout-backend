<?php

namespace App\Console\Commands\DataSync;

/**
 * Operational alias for zcout:sync-premier-league.
 *
 * Prefer: php artisan zcout:sync-premier-league
 */
class ImportPremierLeague extends SyncPremierLeagueCommand
{
    protected $signature = 'zcout:import-pl
        {--dry-run : Fetch and report changes without writing to the database}
        {--detach-missing-players : Set club_id=null for players missing from all current API squads of clubs that remain in the PL}
        {--verify-only : Only run post-sync style invariants against the current database}
        {--rebuild-projections : After a successful apply, rebuild Redis ranking projections}
        {--sleep=0 : Seconds to sleep between squad API requests}';

    protected $description = 'Alias of zcout:sync-premier-league (football-data.org season sync)';
}
