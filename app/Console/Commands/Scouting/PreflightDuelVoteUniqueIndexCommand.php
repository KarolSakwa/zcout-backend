<?php

namespace App\Console\Commands\Scouting;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreflightDuelVoteUniqueIndexCommand extends Command
{
    protected $signature = 'zcout:preflight-duel-vote-unique-index
                            {--apply : Create the unique index when no duplicates are found}
                            {--force : Required to apply in production}';

    protected $description = 'Check for duplicate duel votes before creating votes_unique_duel_voterhash';

    public function handle(): int
    {
        $duplicateGroups = DB::select("
            SELECT duel_id, voter_hash, COUNT(*) AS vote_count
            FROM votes
            WHERE source = 'duel'
            GROUP BY duel_id, voter_hash
            HAVING COUNT(*) > 1
        ");

        $extraRows = DB::table('votes')
            ->where('source', 'duel')
            ->selectRaw('duel_id, voter_hash, COUNT(*) AS c')
            ->groupBy('duel_id', 'voter_hash')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->sum(fn ($row) => max(0, (int) $row->c - 1));

        $this->line('Preflight: votes_unique_duel_voterhash');
        $this->line('Duplicate groups: '.count($duplicateGroups));
        $this->line('Extra duplicate rows: '.$extraRows);

        if (count($duplicateGroups) > 0) {
            $this->error('Duplicates found. Do not create the unique index until they are resolved manually.');

            foreach (array_slice($duplicateGroups, 0, 10) as $row) {
                $this->line(sprintf(
                    '  duel_id=%s voter_hash=%s count=%s',
                    $row->duel_id,
                    $row->voter_hash,
                    $row->vote_count,
                ));
            }

            return self::FAILURE;
        }

        $this->info('No duplicate (duel_id, voter_hash) pairs found.');

        if (! $this->option('apply')) {
            $this->line('Re-run with --apply to create the index after review.');

            return self::SUCCESS;
        }

        if ($this->isProductionApplyBlocked()) {
            $this->error('Refusing --apply in production without --force.');

            return self::FAILURE;
        }

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = \'duel\'
        ');

        $this->info('Created votes_unique_duel_voterhash.');

        return self::SUCCESS;
    }

    public function isProductionApplyBlocked(): bool
    {
        return $this->option('apply')
            && app()->environment('production')
            && ! $this->option('force');
    }
}
