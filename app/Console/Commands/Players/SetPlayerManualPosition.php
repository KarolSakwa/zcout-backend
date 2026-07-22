<?php

namespace App\Console\Commands\Players;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetPlayerManualPosition extends Command
{
    protected $signature = 'zcout:set-player-manual-position
        {playerId : Local player id}
        {--position= : Position id from positions table}
        {--clear : Clear manual position override}';

    protected $description = 'Set or clear manual player position override';

    public function handle(): int
    {
        $playerId = (int) $this->argument('playerId');
        $positionOption = $this->option('position');
        $clear = (bool) $this->option('clear');

        if ($positionOption !== null && $clear) {
            $this->error('Use either --position or --clear, not both.');
            return self::FAILURE;
        }

        $player = $this->loadPlayer($playerId);

        if (!$player) {
            $this->error("Player {$playerId} not found.");
            return self::FAILURE;
        }

        if ($positionOption === null && !$clear) {
            $this->renderPlayerSnapshot($player);
            $this->renderPositionsTable();
            return self::SUCCESS;
        }

        if ($clear) {
            DB::table('players')
                ->where('id', $playerId)
                ->update([
                    'manual_position_id' => null,
                ]);

            $updated = $this->loadPlayer($playerId);

            $this->info("Cleared manual position for player {$playerId}");
            $this->renderPlayerSnapshot($updated);

            return self::SUCCESS;
        }

        if (!is_numeric($positionOption)) {
            $this->error('Position id must be numeric.');
            return self::FAILURE;
        }

        $positionId = (int) $positionOption;

        $position = DB::table('positions')
            ->where('id', $positionId)
            ->first(['id', 'key', 'label', 'short_label']);

        if (!$position) {
            $this->error("Position {$positionId} not found.");
            $this->renderPositionsTable();
            return self::FAILURE;
        }

        DB::table('players')
            ->where('id', $playerId)
            ->update([
                'manual_position_id' => $positionId,
            ]);

        $updated = $this->loadPlayer($playerId);

        $this->info("Updated manual position for player {$playerId}");
        $this->renderPlayerSnapshot($updated);

        return self::SUCCESS;
    }

    private function loadPlayer(int $playerId): ?object
    {
        return DB::table('players as p')
            ->leftJoin('clubs as c', 'c.id', '=', 'p.club_id')
            ->leftJoin('positions as base_pos', 'base_pos.id', '=', 'p.position_id')
            ->leftJoin('positions as fd_pos', 'fd_pos.id', '=', 'p.fd_position_id')
            ->leftJoin('positions as manual_pos', 'manual_pos.id', '=', 'p.manual_position_id')
            ->where('p.id', $playerId)
            ->select([
                'p.id',
                'p.name',
                'p.fd_name',
                'p.manual_display_name',
                'c.name as club_name',
                'p.position_id',
                'p.fd_position_id',
                'p.manual_position_id',
                'base_pos.key as base_position_key',
                'base_pos.label as base_position_label',
                'base_pos.short_label as base_position_short',
                'fd_pos.key as fd_position_key',
                'fd_pos.label as fd_position_label',
                'fd_pos.short_label as fd_position_short',
                'manual_pos.key as manual_position_key',
                'manual_pos.label as manual_position_label',
                'manual_pos.short_label as manual_position_short',
            ])
            ->first();
    }

    private function renderPlayerSnapshot(?object $player): void
    {
        if (!$player) {
            return;
        }

        $effectiveName = $player->manual_display_name ?: ($player->fd_name ?: $player->name);
        $effectivePositionId = $player->manual_position_id ?? $player->fd_position_id ?? $player->position_id;
        $effectivePositionKey = $player->manual_position_key ?: ($player->fd_position_key ?: $player->base_position_key);
        $effectivePositionLabel = $player->manual_position_label ?: ($player->fd_position_label ?: $player->base_position_label);
        $effectivePositionShort = $player->manual_position_short ?: ($player->fd_position_short ?: $player->base_position_short);

        $this->table(
            [
                'id',
                'effective_name',
                'club',
                'base_position',
                'fd_position',
                'manual_position',
                'effective_position',
            ],
            [[
                'id' => $player->id,
                'effective_name' => $effectiveName,
                'club' => $player->club_name,
                'base_position' => $this->formatPosition(
                    $player->position_id,
                    $player->base_position_key,
                    $player->base_position_label,
                    $player->base_position_short
                ),
                'fd_position' => $this->formatPosition(
                    $player->fd_position_id,
                    $player->fd_position_key,
                    $player->fd_position_label,
                    $player->fd_position_short
                ),
                'manual_position' => $this->formatPosition(
                    $player->manual_position_id,
                    $player->manual_position_key,
                    $player->manual_position_label,
                    $player->manual_position_short
                ),
                'effective_position' => $this->formatPosition(
                    $effectivePositionId,
                    $effectivePositionKey,
                    $effectivePositionLabel,
                    $effectivePositionShort
                ),
            ]]
        );
    }

    private function renderPositionsTable(): void
    {
        $positions = DB::table('positions')
            ->select(['id', 'key', 'label', 'short_label'])
            ->orderBy('id')
            ->get()
            ->map(fn ($position) => [
                'id' => $position->id,
                'key' => $position->key,
                'label' => $position->label,
                'short_label' => $position->short_label,
            ])
            ->all();

        $this->table(
            ['id', 'key', 'label', 'short_label'],
            $positions
        );
    }

    private function formatPosition(?int $id, ?string $key, ?string $label, ?string $shortLabel): string
    {
        if ($id === null) {
            return 'null';
        }

        $parts = array_filter([
            "#{$id}",
            $key,
            $shortLabel,
            $label,
        ], fn ($value) => $value !== null && $value !== '');

        return implode(' | ', $parts);
    }
}
