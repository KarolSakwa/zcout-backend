<?php

namespace App\Console\Commands;

use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use App\PremierLeague\PremierLeagueSyncReport;
use App\Services\Ranking\RebuildRankingProjectionsAction;
use Illuminate\Console\Command;
use Throwable;

class SyncPremierLeagueCommand extends Command
{
    protected $signature = 'zcout:sync-premier-league
        {--dry-run : Fetch and report changes without writing to the database}
        {--detach-missing-players : Set club_id=null for players missing from all current API squads of clubs that remain in the PL}
        {--verify-only : Only run post-sync style invariants against the current database}
        {--rebuild-projections : After a successful apply + invariants, rebuild Redis ranking projections}
        {--sleep=0 : Seconds to sleep between squad API requests}';

    protected $description = 'Synchronise clubs and squads with the current Premier League season from football-data.org';

    public function handle(
        RebuildRankingProjectionsAction $rebuildRankingProjectionsAction,
        PremierLeagueSeasonSynchronizer $synchronizer,
    ): int {
        if ((bool) $this->option('verify-only')) {
            $verify = $synchronizer->verify();
            $this->printVerify($verify);

            return ($verify['active_clubs_ok'] ?? false) && (($verify['invalid_active_locks'] ?? 1) === 0)
                ? self::SUCCESS
                : self::FAILURE;
        }

        $report = $synchronizer->sync(
            dryRun: (bool) $this->option('dry-run'),
            detachMissingPlayers: (bool) $this->option('detach-missing-players'),
            sleepSeconds: (int) $this->option('sleep'),
        );

        $this->printReport($report);

        if (! $report->success) {
            return self::FAILURE;
        }

        if (! $report->applied) {
            return self::SUCCESS;
        }

        if (! (bool) $this->option('rebuild-projections')) {
            $this->warn('Season sync completed. Ranking projections require rebuild.');
            $this->line('php artisan app:rebuild-attribute-ranking-projection-command');
            $this->line('php artisan app:rebuild-overall-ranking-projection-command');
            $this->line('Or re-run: php artisan zcout:sync-premier-league --rebuild-projections');

            return self::SUCCESS;
        }

        try {
            $rebuildRankingProjectionsAction->handle();
            $this->info('Ranking projections rebuilt for the active Premier League pool.');
        } catch (Throwable $e) {
            $this->error('Season sync committed to the database, but Redis projection rebuild failed.');
            $this->error($e->getMessage());
            $this->warn('Retry Redis rebuild only (do not re-run sync unless needed):');
            $this->line('php artisan app:rebuild-attribute-ranking-projection-command');
            $this->line('php artisan app:rebuild-overall-ranking-projection-command');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printReport(PremierLeagueSyncReport $report): void
    {
        $this->info($report->dryRun ? '=== Premier League sync DRY-RUN ===' : '=== Premier League sync ===');

        if ($report->errors !== []) {
            $this->error('Errors:');
            foreach ($report->errors as $error) {
                $this->error('  - '.$error);
            }
        }

        if ($report->warnings !== []) {
            $this->warn('Warnings:');
            foreach ($report->warnings as $warning) {
                $this->warn('  - '.$warning);
            }
        }

        $this->newLine();
        $this->info('Counts: '.json_encode($report->counts, JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('Clubs:');
        foreach ($report->clubLines as $line) {
            $this->line('  '.json_encode($line, JSON_UNESCAPED_UNICODE));
        }

        $this->newLine();
        $this->info('Players:');
        foreach ($report->playerLines as $line) {
            $action = (string) ($line['action'] ?? '');
            if (in_array($action, ['keep_inactive'], true)) {
                continue;
            }
            $this->line('  '.$this->formatPlayerLine($line));
        }

        if ($report->lockLines !== []) {
            $this->newLine();
            $this->info('Invalid duel locks:');
            foreach ($report->lockLines as $line) {
                $this->line('  '.json_encode($line, JSON_UNESCAPED_UNICODE));
            }
        }

        if ($report->verify !== []) {
            $this->newLine();
            $this->printVerify($report->verify);
        }

        if ($report->dryRun) {
            $this->newLine();
            $this->info('Dry-run complete. No database changes were written.');
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function formatPlayerLine(array $line): string
    {
        $id = $line['player_id'] ?? 'new';
        $name = $line['name'] ?? '';
        $action = $line['action'] ?? '';

        $parts = ["Player #{$id} {$name}: action={$action}"];

        if (isset($line['club_id']) && is_array($line['club_id'])) {
            $from = $line['club_id']['from'] ?? 'null';
            $to = $line['club_id']['to'] ?? 'null';
            $parts[] = "club_id {$from} → {$to}";
        } elseif (isset($line['club_id'])) {
            $parts[] = 'club_id '.$line['club_id'];
        }

        if (isset($line['external_id']) && is_array($line['external_id'])) {
            $from = $line['external_id']['from'] ?? 'null';
            $to = $line['external_id']['to'] ?? 'null';
            $parts[] = "external_id {$from} → {$to}";
        } elseif (isset($line['external_id'])) {
            $parts[] = 'external_id '.$line['external_id'];
        }

        if (! empty($line['internal_players.id_preserved'])) {
            $parts[] = 'internal players.id preserved='.$this->stringify($line['internal_players.id_preserved']);
        }
        if (! empty($line['ratings_preserved'])) {
            $parts[] = 'ratings preserved';
        }
        if (! empty($line['votes_preserved'])) {
            $parts[] = 'votes preserved';
        }
        if (! empty($line['note'])) {
            $parts[] = (string) $line['note'];
        }

        return implode(' | ', $parts);
    }

    /**
     * @param  array<string, mixed>  $verify
     */
    private function printVerify(array $verify): void
    {
        $this->info('Verify:');
        foreach ($verify as $key => $value) {
            $this->line('  '.$key.': '.$this->stringify($value));
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return (string) $value;
    }
}
