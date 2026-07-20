<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicatePlayerIds = DB::table('player_overalls')
            ->select('player_id')
            ->groupBy('player_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('player_id');

        foreach ($duplicatePlayerIds as $playerId) {
            $this->deduplicatePlayer((int) $playerId);
        }

        Schema::table('player_overalls', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'position']);
            $table->unique('player_id');
        });
    }

    public function down(): void
    {
        Schema::table('player_overalls', function (Blueprint $table) {
            $table->dropUnique(['player_id']);
            $table->unique(['player_id', 'position']);
        });
    }

    private function deduplicatePlayer(int $playerId): void
    {
        $effectivePosition = DB::table('players as p')
            ->leftJoin('positions as manual_pos', 'manual_pos.id', '=', 'p.manual_position_id')
            ->leftJoin('positions as fd_pos', 'fd_pos.id', '=', 'p.fd_position_id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
            ->where('p.id', $playerId)
            ->value(DB::raw('UPPER(COALESCE(manual_pos.short_label, fd_pos.short_label, pos.short_label))'));

        $records = DB::table('player_overalls')
            ->where('player_id', $playerId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $keepId = null;

        if ($effectivePosition) {
            $matching = $records->filter(
                fn ($record) => strtoupper((string) $record->position) === strtoupper((string) $effectivePosition)
            );

            if ($matching->count() === 1) {
                $keepId = (int) $matching->first()->id;
            }
        }

        if ($keepId === null) {
            $keepId = (int) $records->first()->id;
        }

        DB::table('player_overalls')
            ->where('player_id', $playerId)
            ->where('id', '!=', $keepId)
            ->delete();
    }
};
