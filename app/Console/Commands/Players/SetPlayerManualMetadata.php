<?php

namespace App\Console\Commands\Players;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetPlayerManualMetadata extends Command
{
    protected $signature = 'zcout:set-player-manual-metadata
        {playerId : Local player id}
        {--name= : Manual display name}
        {--number= : Manual shirt number}
        {--clear-name : Clear manual display name}
        {--clear-number : Clear manual shirt number}';

    protected $description = 'Set or clear manual player metadata overrides';

    public function handle(): int
    {
        $playerId = (int) $this->argument('playerId');
        $name = $this->option('name');
        $number = $this->option('number');
        $clearName = (bool) $this->option('clear-name');
        $clearNumber = (bool) $this->option('clear-number');

        $player = DB::table('players')
            ->where('id', $playerId)
            ->first([
                'id',
                'name',
                'fd_name',
                'manual_display_name',
                'number',
                'fd_number',
                'manual_number',
            ]);

        if (!$player) {
            $this->error("Player {$playerId} not found.");
            return self::FAILURE;
        }

        if ($name !== null && $clearName) {
            $this->error('Use either --name or --clear-name, not both.');
            return self::FAILURE;
        }

        if ($number !== null && $clearNumber) {
            $this->error('Use either --number or --clear-number, not both.');
            return self::FAILURE;
        }

        $payload = [];

        if ($name !== null) {
            $trimmedName = trim((string) $name);

            if ($trimmedName === '') {
                $this->error('Manual name cannot be empty.');
                return self::FAILURE;
            }

            $payload['manual_display_name'] = $trimmedName;
        }

        if ($clearName) {
            $payload['manual_display_name'] = null;
        }

        if ($number !== null) {
            if (!is_numeric($number)) {
                $this->error('Manual number must be numeric.');
                return self::FAILURE;
            }

            $parsedNumber = (int) $number;

            if ($parsedNumber < 1 || $parsedNumber > 99) {
                $this->error('Manual number must be between 1 and 99.');
                return self::FAILURE;
            }

            $payload['manual_number'] = $parsedNumber;
        }

        if ($clearNumber) {
            $payload['manual_number'] = null;
        }

        if ($payload === []) {
            $this->error('Nothing to update. Pass --name, --number, --clear-name or --clear-number.');
            return self::FAILURE;
        }

        DB::table('players')
            ->where('id', $playerId)
            ->update($payload);

        $updated = DB::table('players')
            ->where('id', $playerId)
            ->first([
                'id',
                'name',
                'fd_name',
                'manual_display_name',
                'number',
                'fd_number',
                'manual_number',
            ]);

        $effectiveName = $updated->manual_display_name ?: ($updated->fd_name ?: $updated->name);
        $effectiveNumber = $updated->manual_number ?? $updated->fd_number ?? $updated->number;

        $this->info("Updated player {$updated->id}");
        $this->line("effective_name: {$effectiveName}");
        $this->line('effective_number: ' . ($effectiveNumber ?? 'null'));

        return self::SUCCESS;
    }
}
