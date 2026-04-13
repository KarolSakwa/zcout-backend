<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FindPlayerMetadata extends Command
{
    protected $signature = 'zcout:find-player-metadata
        {query : Player name fragment}
        {--limit=20 : Max results}';

    protected $description = 'Find players and show metadata fields useful for manual overrides';

    public function handle(): int
    {
        $query = trim((string) $this->argument('query'));
        $limit = max(1, min(100, (int) $this->option('limit')));

        $players = DB::table('players as p')
            ->leftJoin('clubs as c', 'c.id', '=', 'p.club_id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
            ->where(function ($q) use ($query) {
                $q->where('p.name', 'ilike', '%' . $query . '%')
                    ->orWhere('p.fd_name', 'ilike', '%' . $query . '%')
                    ->orWhere('p.manual_display_name', 'ilike', '%' . $query . '%');
            })
            ->select([
                'p.id',
                'p.name',
                'p.fd_name',
                'p.manual_display_name',
                'p.number',
                'p.fd_number',
                'p.manual_number',
                'c.name as club_name',
                'pos.short_label as position',
            ])
            ->orderByRaw("
                CASE
                    WHEN p.manual_display_name ILIKE ? THEN 0
                    WHEN p.fd_name ILIKE ? THEN 1
                    WHEN p.name ILIKE ? THEN 2
                    ELSE 3
                END
            ", [$query . '%', $query . '%', $query . '%'])
            ->orderBy('p.name')
            ->limit($limit)
            ->get();

        if ($players->isEmpty()) {
            $this->warn('No players found.');
            return self::SUCCESS;
        }

        $rows = $players->map(function ($player) {
            $effectiveName = $player->manual_display_name ?: ($player->fd_name ?: $player->name);
            $effectiveNumber = $player->manual_number ?? $player->fd_number ?? $player->number;

            return [
                'id' => $player->id,
                'effective_name' => $effectiveName,
                'effective_number' => $effectiveNumber,
                'manual_name' => $player->manual_display_name,
                'fd_name' => $player->fd_name,
                'base_name' => $player->name,
                'manual_number' => $player->manual_number,
                'fd_number' => $player->fd_number,
                'base_number' => $player->number,
                'club' => $player->club_name,
                'pos' => $player->position,
            ];
        })->all();

        $this->table([
            'id',
            'effective_name',
            'effective_number',
            'manual_name',
            'fd_name',
            'base_name',
            'manual_number',
            'fd_number',
            'base_number',
            'club',
            'pos',
        ], $rows);

        return self::SUCCESS;
    }
}
