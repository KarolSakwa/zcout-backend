<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Players\ZcoutBaselineEditCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ZcoutBaselineEditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_premier_league_player_is_visible(): void
    {
        $activeClubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $activePlayerId = $this->createPlayer('Active Player', 'active-player', $activeClubId, $positionId);

        $players = $this->loadPlayers();

        $this->assertContains($activePlayerId, $players->pluck('id')->all());
    }

    public function test_player_without_club_is_visible_with_without_club_label(): void
    {
        $positionId = $this->createPosition();
        $noClubPlayerId = $this->createPlayer('No Club Player', 'no-club-player', null, $positionId);

        $players = $this->loadPlayers();
        $noClubPlayer = $players->firstWhere('id', $noClubPlayerId);

        $this->assertNotNull($noClubPlayer);
        $this->assertSame('Without club', $noClubPlayer['club']);
    }

    public function test_player_from_inactive_club_is_not_visible(): void
    {
        $inactiveClubId = $this->createClub('Inactive', false);
        $positionId = $this->createPosition();
        $inactivePlayerId = $this->createPlayer('Inactive Player', 'inactive-player', $inactiveClubId, $positionId);

        $players = $this->loadPlayers();

        $this->assertNotContains($inactivePlayerId, $players->pluck('id')->all());
    }

    public function test_player_option_finds_player_without_club(): void
    {
        $positionId = $this->createPosition();
        $noClubPlayerId = $this->createPlayer('No Club Player', 'no-club-player', null, $positionId);

        $command = $this->makeCommand(['--player' => (string) $noClubPlayerId]);
        $players = $this->loadPlayers();
        $baseline = $this->emptyBaseline();

        $resolved = $this->invokeProtected($command, 'resolveStartIndex', [$players, $baseline]);

        $this->assertSame('ok', $resolved['status']);
        $this->assertSame($players->search(fn (array $player) => $player['id'] === $noClubPlayerId), $resolved['index']);
    }

    public function test_completed_total_includes_players_without_club(): void
    {
        $activeClubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $activePlayerId = $this->createPlayer('Active Player', 'active-player', $activeClubId, $positionId);
        $noClubPlayerId = $this->createPlayer('No Club Player', 'no-club-player', null, $positionId);

        $players = $this->loadPlayers();
        $baseline = $this->emptyBaseline();

        $this->assertSame(2, $players->count());

        $baseline['players'][(string) $activePlayerId] = $this->completePlayerEntry('Active Player', 'RB', 'Active PL');
        $baseline['players'][(string) $noClubPlayerId] = $this->completePlayerEntry('No Club Player', 'RB', 'Without club');

        $command = $this->makeCommand();
        $completed = $this->invokeProtected($command, 'countCompletedPlayers', [$players, $baseline]);

        $this->assertSame(2, $completed);
        $this->assertSame(2, $players->count());
    }

    private function loadPlayers()
    {
        $command = app(ZcoutBaselineEditCommand::class);

        return $this->invokeProtected($command, 'loadPlayers');
    }

    private function makeCommand(array $options = []): ZcoutBaselineEditCommand
    {
        $command = app(ZcoutBaselineEditCommand::class);
        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();
        $command->setLaravel($this->app);
        $command->setInput($input);
        $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

        return $command;
    }

    private function invokeProtected(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    private function emptyBaseline(): array
    {
        return [
            'version' => 1,
            'format_version' => 2,
            'competition' => 'Premier League',
            'season' => '2026/27',
            'generated_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'players' => [],
        ];
    }

    private function completePlayerEntry(string $name, string $position, string $club): array
    {
        $attributes = [];

        foreach (config('zcout_attributes.outfield', []) as $definition) {
            $attributes[(string) $definition['key']] = 50;
        }

        return [
            'name' => $name,
            'position' => $position,
            'club' => $club,
            'attributes' => $attributes,
            'review_attributes' => [],
        ];
    }

    private function createClub(string $name, bool $isCurrentPremierLeague): int
    {
        return (int) DB::table('clubs')->insertGetId([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_current_premier_league' => $isCurrentPremierLeague,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosition(): int
    {
        return (int) DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(string $name, string $slug, ?int $clubId, int $positionId): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'club_id' => $clubId,
            'position_id' => $positionId,
        ]);
    }
}
