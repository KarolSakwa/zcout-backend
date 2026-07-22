<?php

namespace App\Actions\Players;

use App\Models\Player;
use InvalidArgumentException;

class ValidatePlayerArchetypeLabelAction
{
    public function execute(string $label, Player $player): string
    {
        $label = trim($label);
        $label = trim($label, "\"'");
        $label = rtrim($label, '.');

        if ($label === '') {
            throw new InvalidArgumentException('Archetype label is empty.');
        }

        if (str_word_count($label) > 4) {
            throw new InvalidArgumentException('Archetype label has more than 4 words.');
        }

        $playerName = $player->effective_name ?? $player->name;

        if ($playerName && str_contains(strtolower($label), strtolower($playerName))) {
            throw new InvalidArgumentException('Archetype label contains player name.');
        }

        if ($player->club && str_contains(strtolower($label), strtolower($player->club))) {
            throw new InvalidArgumentException('Archetype label contains club name.');
        }

        return $label;
    }
}
