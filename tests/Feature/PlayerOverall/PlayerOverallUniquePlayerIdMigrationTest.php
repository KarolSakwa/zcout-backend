<?php

namespace Tests\Feature\PlayerOverall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlayerOverallUniquePlayerIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_keeps_record_matching_effective_position_when_unique(): void
    {
        $rwPositionId = $this->createPosition('RW', 'rw', 'Right Winger');

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Winger',
            'slug' => 'winger-dedup',
            'fd_position_id' => $rwPositionId,
        ]);

        $this->rollbackPlayerOverallUniqueMigration();

        $attOverallId = DB::table('player_overalls')->insertGetId([
            'player_id' => $playerId,
            'position' => 'ATT',
            'overall' => 82.00,
            'confidence' => 10.00,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subHour(),
        ]);

        $rwOverallId = DB::table('player_overalls')->insertGetId([
            'player_id' => $playerId,
            'position' => 'RW',
            'overall' => 80.00,
            'confidence' => 8.00,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(2),
        ]);

        $this->runPlayerOverallUniqueMigrationUp();

        $this->assertSame(1, DB::table('player_overalls')->where('player_id', $playerId)->count());
        $this->assertSame($rwOverallId, (int) DB::table('player_overalls')->where('player_id', $playerId)->value('id'));
        $this->assertSame('RW', DB::table('player_overalls')->where('player_id', $playerId)->value('position'));
        $this->assertNotSame($attOverallId, (int) DB::table('player_overalls')->where('player_id', $playerId)->value('id'));
    }

    public function test_migration_keeps_newest_record_when_effective_position_does_not_match_uniquely(): void
    {
        $cmPositionId = $this->createPosition('CM', 'cm', 'Central Midfielder');

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Midfielder',
            'slug' => 'midfielder-dedup',
            'fd_position_id' => $cmPositionId,
        ]);

        $this->rollbackPlayerOverallUniqueMigration();

        $olderOverallId = DB::table('player_overalls')->insertGetId([
            'player_id' => $playerId,
            'position' => 'ATT',
            'overall' => 82.00,
            'confidence' => 10.00,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(2),
        ]);

        $newerOverallId = DB::table('player_overalls')->insertGetId([
            'player_id' => $playerId,
            'position' => 'MID',
            'overall' => 80.00,
            'confidence' => 8.00,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
        ]);

        $this->runPlayerOverallUniqueMigrationUp();

        $this->assertSame(1, DB::table('player_overalls')->where('player_id', $playerId)->count());
        $this->assertSame($newerOverallId, (int) DB::table('player_overalls')->where('player_id', $playerId)->value('id'));
        $this->assertNotSame($olderOverallId, (int) DB::table('player_overalls')->where('player_id', $playerId)->value('id'));
    }

    public function test_migration_down_restores_composite_unique_index(): void
    {
        $this->assertTrue($this->playerOverallUniquePlayerIdIndexExists());

        $this->runPlayerOverallUniqueMigrationDown();

        $this->assertFalse($this->playerOverallUniquePlayerIdIndexExists());
        $this->assertTrue($this->playerOverallCompositeUniqueIndexExists());

        $this->runPlayerOverallUniqueMigrationUp();
    }

    private function createPosition(string $shortLabel, string $key, string $label): int
    {
        return (int) DB::table('positions')->insertGetId([
            'short_label' => $shortLabel,
            'key' => $key,
            'label' => $label,
            'group' => 'MID',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_20_190000_deduplicate_player_overalls_and_add_unique_player_id.php');
    }

    private function rollbackPlayerOverallUniqueMigration(): void
    {
        if (! $this->playerOverallUniquePlayerIdIndexExists()) {
            return;
        }

        $this->runPlayerOverallUniqueMigrationDown();
    }

    private function runPlayerOverallUniqueMigrationUp(): void
    {
        $this->migration()->up();
    }

    private function runPlayerOverallUniqueMigrationDown(): void
    {
        $this->migration()->down();
    }

    private function playerOverallUniquePlayerIdIndexExists(): bool
    {
        return $this->indexExists('player_overalls_player_id_unique');
    }

    private function playerOverallCompositeUniqueIndexExists(): bool
    {
        return $this->indexExists('player_overalls_player_id_position_unique');
    }

    private function indexExists(string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $result = DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                ['player_overalls', $indexName]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('player_overalls')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }
}
