<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ZcoutBaselineEditCommand extends Command
{
    protected $signature = 'zcout:baseline-edit {--player=} {--file=database/seed-baselines/baseline_v1.json} {--review}';

    protected $description = 'Edit baseline seed ratings stored in a JSON file.';

    public function handle(): int
    {
        $filePath = base_path((string) $this->option('file'));
        $baseline = $this->loadBaseline($filePath);
        $players = $this->loadPlayers();

        if ($players->isEmpty()) {
            $this->error('No players found.');
            return self::FAILURE;
        }

        $resolvedStart = $this->resolveStartIndex($players, $baseline);

        if ($resolvedStart['status'] === 'player_not_found') {
            $this->error('Player not found.');
            return self::FAILURE;
        }

        $completedCount = $this->countCompletedPlayers($players, $baseline);
        $totalPlayers = $players->count();

        if ($resolvedStart['index'] === null) {
            $this->line("Completed: {$completedCount}/{$totalPlayers}. Starting from: none");
            return self::SUCCESS;
        }

        $startIndex = $resolvedStart['index'];
        $startPlayer = $players[$startIndex];

        $this->line("Completed: {$completedCount}/{$totalPlayers}. Starting from: {$startPlayer['id']} | {$startPlayer['name']}");

        for ($playerIndex = $startIndex; $playerIndex < $players->count(); $playerIndex++) {
            $player = $players[$playerIndex];
            $definitions = $this->promptDefinitionsForPlayer($player, $baseline);

            if ($definitions === []) {
                $playerIndex++;
                continue;
            }

            $attributeIndex = $this->resolveStartingAttributeIndex($player, $baseline);

            while ($attributeIndex < count($definitions)) {
                $definition = $definitions[$attributeIndex];
                $attributeKey = $definition['key'];
                $currentValue = $baseline['players'][(string) $player['id']]['attributes'][$attributeKey] ?? null;
                $isReview = in_array(
                    $attributeKey,
                    $baseline['players'][(string) $player['id']]['review_attributes'] ?? [],
                    true,
                );

                $this->newLine();
                $this->line('Progress: '.$this->countCompletedPlayers($players, $baseline).'/'.$totalPlayers.' completed');
                $this->line('Player: '.$player['id'].' | '.$player['name'].' | '.$player['position'].' | '.$player['club']);
                $this->line('Attribute '.($attributeIndex + 1).'/'.count($definitions).': '.$definition['label']);
                $this->line('Current: '.$this->formatCurrentValue($currentValue, $isReview));
                $this->line('Input (1-99, 1-99*, b, q):');

                $input = $this->readInput();

                if ($input === 'q') {
                    return self::SUCCESS;
                }

                if ($input === 'b') {
                    if ($attributeIndex > 0) {
                        $attributeIndex--;
                    }

                    continue;
                }

                $parsed = $this->parseRatingInput($input);

                if ($parsed === null) {
                    $this->error('Invalid input. Enter 1-99, 1-99*, b or q.');
                    continue;
                }

                $baseline = $this->applyAttributeValue(
                    $baseline,
                    $player,
                    $definitions,
                    $attributeKey,
                    $parsed['value'],
                    $parsed['review'],
                );

                $this->saveBaseline($filePath, $baseline);
                $attributeIndex++;
            }
        }

        return self::SUCCESS;
    }

    protected function loadPlayers()
    {
        return DB::table('players as p')
            ->leftJoin('player_reputation_stats as prs', 'prs.player_id', '=', 'p.id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
            ->select([
                'p.id',
                'p.name',
                'p.club',
                'pos.short_label as position',
                'prs.player_rep',
            ])
            ->orderByRaw('prs.player_rep DESC NULLS LAST')
            ->orderBy('p.name')
            ->orderBy('p.id')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'name' => $this->pickString($row, ['name', 'player_name'], 'Unknown Player'),
                    'position' => strtoupper($this->pickString($row, ['position'], '-')),
                    'club' => $this->pickString($row, ['club', 'club_name', 'current_club', 'current_club_name'], '-'),
                ];
            })
            ->values();
    }

    protected function resolveStartIndex($players, array $baseline): array
    {
        $playerOption = $this->option('player');
        $reviewMode = (bool) $this->option('review');

        if ($playerOption !== null) {
            $targetId = (int) $playerOption;
            $index = $players->search(fn (array $player) => $player['id'] === $targetId);

            if ($index === false) {
                return [
                    'status' => 'player_not_found',
                    'index' => null,
                ];
            }

            return [
                'status' => 'ok',
                'index' => (int) $index,
            ];
        }

        foreach ($players as $index => $player) {
            if ($reviewMode) {
                if ($this->hasPlayerReviewPending($player, $baseline)) {
                    return [
                        'status' => 'ok',
                        'index' => $index,
                    ];
                }

                continue;
            }

            if (! $this->isPlayerComplete($player, $baseline)) {
                return [
                    'status' => 'ok',
                    'index' => $index,
                ];
            }
        }

        return [
            'status' => 'ok',
            'index' => null,
        ];
    }

    protected function hasPlayerReviewPending(array $player, array $baseline): bool
    {
        $reviewAttributes = $baseline['players'][(string) $player['id']]['review_attributes'] ?? [];

        return is_array($reviewAttributes) && count($reviewAttributes) > 0;
    }

    protected function isPlayerComplete(array $player, array $baseline): bool
    {
        $definitions = $this->attributeDefinitionsForPosition($player['position']);
        $attributes = $baseline['players'][(string) $player['id']]['attributes'] ?? [];

        foreach ($definitions as $definition) {
            $value = $attributes[$definition['key']] ?? null;

            if (! is_int($value) || $value < 1 || $value > 99) {
                return false;
            }
        }

        return true;
    }

    protected function countCompletedPlayers($players, array $baseline): int
    {
        $count = 0;

        foreach ($players as $player) {
            if ($this->isPlayerComplete($player, $baseline)) {
                $count++;
            }
        }

        return $count;
    }

    protected function attributeDefinitionsForPosition(?string $position): array
    {
        $configKey = strtoupper((string) $position) === 'GK' ? 'zcout_attributes.gk' : 'zcout_attributes.outfield';
        $definitions = config($configKey, []);

        if (! is_array($definitions) || $definitions === []) {
            throw new RuntimeException("Missing attribute config for [{$configKey}].");
        }

        return array_map(function ($definition) {
            return [
                'key' => (string) ($definition['key'] ?? ''),
                'label' => (string) ($definition['label'] ?? Str::headline((string) ($definition['key'] ?? ''))),
            ];
        }, $definitions);
    }

    protected function parseRatingInput(string $input): ?array
    {
        if (! preg_match('/^(\d{1,2})(\*)?$/', $input, $matches)) {
            return null;
        }

        $value = (int) $matches[1];

        if ($value < 1 || $value > 99) {
            return null;
        }

        return [
            'value' => $value,
            'review' => isset($matches[2]) && $matches[2] === '*',
        ];
    }

    protected function applyAttributeValue(
        array $baseline,
        array $player,
        array $definitions,
        string $attributeKey,
        int $value,
        bool $review,
    ): array {
        $playerKey = (string) $player['id'];

        if (! isset($baseline['players'][$playerKey])) {
            $baseline['players'][$playerKey] = [
                'name' => $player['name'],
                'position' => $player['position'],
                'club' => $player['club'],
                'attributes' => [],
                'review_attributes' => [],
            ];
        }

        $baseline['players'][$playerKey]['name'] = $player['name'];
        $baseline['players'][$playerKey]['position'] = $player['position'];
        $baseline['players'][$playerKey]['club'] = $player['club'];
        $baseline['players'][$playerKey]['attributes'][$attributeKey] = $value;

        $reviewAttributes = $baseline['players'][$playerKey]['review_attributes'] ?? [];
        $reviewAttributes = array_values(array_filter($reviewAttributes, fn ($key) => $key !== $attributeKey));

        if ($review) {
            $reviewAttributes[] = $attributeKey;
        }

        $order = array_flip(array_column($definitions, 'key'));

        usort($reviewAttributes, function (string $left, string $right) use ($order) {
            return ($order[$left] ?? PHP_INT_MAX) <=> ($order[$right] ?? PHP_INT_MAX);
        });

        $baseline['players'][$playerKey]['review_attributes'] = array_values(array_unique($reviewAttributes));

        return $baseline;
    }

    protected function formatCurrentValue(mixed $value, bool $isReview): string
    {
        if (! is_int($value)) {
            return '-';
        }

        return $isReview ? $value.' [review]' : (string) $value;
    }

    protected function loadBaseline(string $filePath): array
    {
        if (! file_exists($filePath)) {
            $baseline = $this->emptyBaseline();
            $this->saveBaseline($filePath, $baseline);
            return $baseline;
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read baseline file.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid baseline JSON.');
        }

        return $this->normalizeBaseline($decoded);
    }

    protected function saveBaseline(string $filePath, array $baseline): void
    {
        $directory = dirname($filePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create baseline directory.');
        }

        $baseline['version'] = 1;
        $baseline['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode baseline JSON.');
        }

        if (file_put_contents($filePath, $json.PHP_EOL) === false) {
            throw new RuntimeException('Unable to write baseline file.');
        }
    }

    protected function normalizeBaseline(array $baseline): array
    {
        $players = [];

        foreach (($baseline['players'] ?? []) as $playerId => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $attributes = [];

            foreach (($entry['attributes'] ?? []) as $attributeKey => $value) {
                $intValue = (int) $value;

                if ($intValue >= 1 && $intValue <= 99) {
                    $attributes[(string) $attributeKey] = $intValue;
                }
            }

            $reviewAttributes = array_values(array_unique(array_map(
                fn ($key) => (string) $key,
                array_filter($entry['review_attributes'] ?? [], fn ($key) => is_string($key) || is_numeric($key)),
            )));

            $players[(string) $playerId] = [
                'name' => (string) ($entry['name'] ?? ''),
                'position' => strtoupper((string) ($entry['position'] ?? '')),
                'club' => (string) ($entry['club'] ?? '-'),
                'attributes' => $attributes,
                'review_attributes' => $reviewAttributes,
            ];
        }

        return [
            'version' => 1,
            'updated_at' => (string) ($baseline['updated_at'] ?? CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z')),
            'players' => $players,
        ];
    }

    protected function emptyBaseline(): array
    {
        return [
            'version' => 1,
            'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'players' => [],
        ];
    }

    protected function pickString(object $row, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (property_exists($row, $key) && $row->{$key} !== null && trim((string) $row->{$key}) !== '') {
                return trim((string) $row->{$key});
            }
        }

        return $fallback;
    }

    protected function readInput(): string
    {
        $this->output->write('> ');
        $input = fgets(STDIN);

        if ($input === false) {
            return 'q';
        }

        return trim($input);
    }

    protected function resolveStartingAttributeIndex(array $player, array $baseline): int
    {
        $definitions = $this->promptDefinitionsForPlayer($player, $baseline);
        $attributes = $baseline['players'][(string) $player['id']]['attributes'] ?? [];

        foreach ($definitions as $index => $definition) {
            $value = $attributes[$definition['key']] ?? null;

            if (! is_int($value) || $value < 1 || $value > 99) {
                return $index;
            }
        }

        return 0;
    }

    protected function promptDefinitionsForPlayer(array $player, array $baseline): array
    {
        $definitions = $this->attributeDefinitionsForPosition($player['position']);

        if (! $this->option('review')) {
            return $definitions;
        }

        $reviewAttributes = $baseline['players'][(string) $player['id']]['review_attributes'] ?? [];

        if (! is_array($reviewAttributes) || $reviewAttributes === []) {
            return [];
        }

        return array_values(array_filter(
            $definitions,
            fn (array $definition) => in_array($definition['key'], $reviewAttributes, true),
        ));
    }
}
