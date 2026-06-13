<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Actions\BuildPlayerArchetypeFingerprintAction;
use App\Models\Player;
use App\Models\PlayerArchetype;
use App\Actions\ComparePlayerArchetypeFingerprintsAction;
use App\Actions\BuildPlayerArchetypeInputSnapshotAction;
use App\Actions\BuildPlayerArchetypePromptAction;
use App\Actions\ValidatePlayerArchetypeLabelAction;
use App\Services\LlmPlayerArchetypeClient;

class GeneratePlayerArchetypesCommand extends Command
{
    protected $signature = 'zcout:generate-player-archetypes {--player-id=} {--limit=10} {--force} {--dry-run}';

    protected $description = 'Generate AI scouting archetype labels for players';

    public function handle(
        BuildPlayerArchetypeFingerprintAction $buildFingerprint,
        ComparePlayerArchetypeFingerprintsAction $compareFingerprints,
        BuildPlayerArchetypeInputSnapshotAction $buildInputSnapshot,
        BuildPlayerArchetypePromptAction $buildPrompt,
        LlmPlayerArchetypeClient $llmClient,
        ValidatePlayerArchetypeLabelAction $validateLabel,
    ): int
    {
        $query = Player::query();

        if ($this->option('player-id')) {
            $query->where('id', $this->option('player-id'));
        }

        $players = $query->limit((int) $this->option('limit'))->get();

        foreach ($players as $player) {
            $fingerprint = $buildFingerprint->execute($player);

            $existingArchetype = PlayerArchetype::where('player_id', $player->id)
                ->where('language', 'en')
                ->first();

            if (!$existingArchetype) {
                $comparison = [
                    'significant' => true,
                    'reason' => 'missing_archetype',
                ];
            } else {
                $comparison = $compareFingerprints->execute(
                    previous: $existingArchetype->fingerprint_payload,
                    current: $fingerprint['payload'],
                    force: (bool) $this->option('force'),
                );
            }

            if (!$comparison['significant']) {
                $this->line($player->id . ' | ' . $player->effective_name . ' | SKIP (' . $comparison['reason'] . ')');
                continue;
            }

            $inputSnapshot = $buildInputSnapshot->execute($player);

            if (!$inputSnapshot) {
                $this->warn($player->id . ' | ' . $player->effective_name . ' | SKIP (insufficient data)');
                continue;
            }

            $prompt = $buildPrompt->execute($inputSnapshot);

            $label = $llmClient->generate(
                $prompt['system'],
                $prompt['user'],
            );

            $label = $validateLabel->execute($label, $player);

            if (!$this->option('dry-run')) {
                PlayerArchetype::updateOrCreate(
                    [
                        'player_id' => $player->id,
                        'language' => 'en',
                    ],
                    [
                        'label' => $label,
                        'fingerprint_hash' => $fingerprint['hash'],
                        'fingerprint_payload' => $fingerprint['payload'],
                        'input_snapshot' => $inputSnapshot,
                        'prompt_version' => 'player_archetype_v1',
                        'model' => config('services.openai.player_archetype_model'),
                        'generated_at' => now(),
                        'last_error' => null,
                    ]
                );
            }

            $this->info($player->id . ' | ' . $player->effective_name . ' | LABEL: ' . $label);
        }

        return self::SUCCESS;
    }
}
