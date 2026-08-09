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

    public function test_complete_player_is_skipped_after_incomplete_player_is_finished(): void
    {
        $clubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $incompletePlayerId = $this->createPlayer('Incomplete Player', 'incomplete-player', $clubId, $positionId);
        $completePlayerId = $this->createPlayer('Later Complete Player', 'later-complete-player', $clubId, $positionId);

        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $incompletePlayerId] = $this->playerEntryMissingLastAttribute('Incomplete Player', 'RB', 'Active PL');
        $baseline['players'][(string) $completePlayerId] = $this->completePlayerEntry('Later Complete Player', 'RB', 'Active PL');
        $completePlayerPaceBefore = $baseline['players'][(string) $completePlayerId]['attributes']['pace'];

        $filePath = $this->writeTempBaseline($baseline);
        $output = $this->runCommandWithInputs(
            ['--file' => $filePath],
            ['77', 'q'],
        );

        $updated = json_decode((string) file_get_contents(base_path($filePath)), true);
        $this->assertSame(77, $updated['players'][(string) $incompletePlayerId]['attributes']['free_kicks']);
        $this->assertSame($completePlayerPaceBefore, $updated['players'][(string) $completePlayerId]['attributes']['pace']);
        $this->assertStringNotContainsString('Later Complete Player', $output);
    }

    public function test_complete_player_is_not_re_rated_after_club_change(): void
    {
        $clubId = $this->createClub('New Club', true);
        $positionId = $this->createPosition();
        $incompletePlayerId = $this->createPlayer('Incomplete Player', 'incomplete-player', $clubId, $positionId);
        $completePlayerId = $this->createPlayer('Later Complete Player', 'later-complete-player', $clubId, $positionId);

        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $incompletePlayerId] = $this->playerEntryMissingLastAttribute('Incomplete Player', 'RB', 'New Club');
        $baseline['players'][(string) $completePlayerId] = $this->completePlayerEntry('Later Complete Player', 'RB', 'Old Club');
        $completePlayerAttributesBefore = $baseline['players'][(string) $completePlayerId]['attributes'];

        $filePath = $this->writeTempBaseline($baseline);
        $output = $this->runCommandWithInputs(
            ['--file' => $filePath],
            ['66', 'q'],
        );

        $updated = json_decode((string) file_get_contents(base_path($filePath)), true);
        $this->assertSame($completePlayerAttributesBefore, $updated['players'][(string) $completePlayerId]['attributes']);
        $this->assertStringNotContainsString('Later Complete Player', $output);
    }

    public function test_complete_player_without_club_is_not_re_rated(): void
    {
        $clubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $incompletePlayerId = $this->createPlayer('Incomplete Player', 'incomplete-player', $clubId, $positionId);
        $noClubPlayerId = $this->createPlayer('Later No Club Player', 'later-no-club-player', null, $positionId);

        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $incompletePlayerId] = $this->playerEntryMissingLastAttribute('Incomplete Player', 'RB', 'Active PL');
        $baseline['players'][(string) $noClubPlayerId] = $this->completePlayerEntry('Later No Club Player', 'RB', 'Without club');
        $noClubAttributesBefore = $baseline['players'][(string) $noClubPlayerId]['attributes'];

        $filePath = $this->writeTempBaseline($baseline);
        $output = $this->runCommandWithInputs(
            ['--file' => $filePath],
            ['88', 'q'],
        );

        $updated = json_decode((string) file_get_contents(base_path($filePath)), true);
        $this->assertSame($noClubAttributesBefore, $updated['players'][(string) $noClubPlayerId]['attributes']);
        $this->assertStringNotContainsString('Later No Club Player', $output);
    }

    public function test_resolve_starting_attribute_index_returns_definition_count_for_complete_player(): void
    {
        $clubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Complete Player', 'complete-player', $clubId, $positionId);

        $players = $this->loadPlayers();
        $player = $players->firstWhere('id', $playerId);
        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $playerId] = $this->completePlayerEntry('Complete Player', 'RB', 'Active PL');

        $command = $this->makeCommand();
        $definitions = $this->invokeProtected($command, 'promptDefinitionsForPlayer', [$player, $baseline]);
        $index = $this->invokeProtected($command, 'resolveStartingAttributeIndex', [$player, $baseline]);

        $this->assertSame(count($definitions), $index);
    }

    public function test_resolve_starting_attribute_index_starts_at_first_missing_attribute(): void
    {
        $clubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Partial Player', 'partial-player', $clubId, $positionId);

        $players = $this->loadPlayers();
        $player = $players->firstWhere('id', $playerId);
        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $playerId] = $this->playerEntryMissingAttribute('Partial Player', 'RB', 'Active PL', 'acceleration');

        $command = $this->makeCommand();
        $index = $this->invokeProtected($command, 'resolveStartingAttributeIndex', [$player, $baseline]);

        $this->assertSame(1, $index);
    }

    public function test_review_mode_does_not_skip_next_player_when_current_has_no_review_attributes(): void
    {
        $clubId = $this->createClub('Active PL', true);
        $positionId = $this->createPosition();
        $playerOneId = $this->createPlayer('Player One', 'player-one', $clubId, $positionId);
        $playerTwoId = $this->createPlayer('Player Two', 'player-two', $clubId, $positionId);

        $baseline = $this->emptyBaseline();
        $baseline['players'][(string) $playerOneId] = $this->completePlayerEntry('Player One', 'RB', 'Active PL');
        $baseline['players'][(string) $playerTwoId] = $this->completePlayerEntry('Player Two', 'RB', 'Active PL');
        $baseline['players'][(string) $playerTwoId]['review_attributes'] = ['pace'];

        $filePath = $this->writeTempBaseline($baseline);
        $output = $this->runCommandWithInputs(
            ['--file' => $filePath, '--review' => true, '--player' => (string) $playerOneId],
            ['55', 'q'],
        );

        $updated = json_decode((string) file_get_contents(base_path($filePath)), true);
        $this->assertStringContainsString('Player Two', $output);
        $this->assertSame(55, $updated['players'][(string) $playerTwoId]['attributes']['pace']);
        $this->assertSame([], $updated['players'][(string) $playerTwoId]['review_attributes']);
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

    private function playerEntryMissingLastAttribute(string $name, string $position, string $club): array
    {
        $entry = $this->completePlayerEntry($name, $position, $club);
        $definitions = config('zcout_attributes.outfield', []);
        $lastKey = (string) end($definitions)['key'];
        unset($entry['attributes'][$lastKey]);

        return $entry;
    }

    private function playerEntryMissingAttribute(string $name, string $position, string $club, string $attributeKey): array
    {
        $entry = $this->completePlayerEntry($name, $position, $club);
        unset($entry['attributes'][$attributeKey]);

        return $entry;
    }

    private function writeTempBaseline(array $baseline): string
    {
        $relativePath = 'storage/framework/testing/baseline_'.uniqid('', true).'.json';
        $filePath = base_path($relativePath);
        $directory = dirname($filePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $relativePath;
    }

    private function runCommandWithInputs(array $options, array $inputs): string
    {
        $command = new class($inputs) extends ZcoutBaselineEditCommand {
            private array $inputs;

            private int $inputIndex = 0;

            public function __construct(array $inputs)
            {
                parent::__construct();
                $this->inputs = $inputs;
            }

            protected function readInput(): string
            {
                return $this->inputs[$this->inputIndex++] ?? 'q';
            }
        };

        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();
        $command->setLaravel($this->app);
        $command->setInput($input);
        $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));
        $command->handle();

        return $output->fetch();
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
