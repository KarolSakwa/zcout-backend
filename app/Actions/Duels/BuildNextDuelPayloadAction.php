<?php

namespace App\Actions\Duels;

use App\Models\Player;

final class BuildNextDuelPayloadAction
{
    public function handle(array $context): array
    {
        $attribute = $context['attribute'] ?? null;
        $duel = $context['duel'] ?? null;
        $players = $context['players'] ?? null;
        $matchmaking = $context['matchmaking'] ?? [];
        $debug = $context['debug'] ?? null;

        if (!$attribute || !$duel || !$players) {
            return [];
        }

        $pA = $players[(int) $duel->player_a_id] ?? null;
        $pB = $players[(int) $duel->player_b_id] ?? null;

        if (!$pA || !$pB) {
            return [];
        }

        $toApi = function (Player $p) {
            return [
                'id' => $p->id,
                'name' => $p->effective_name,
                'number' => $p->effective_number,
                'slug' => $p->slug,
                'position' => $p->effective_position_short
                    ?? $p->effective_position_key
                    ?? $p->effective_position_label
                    ?? null,
                'country' => $p->countryRef ? [
                    'id' => $p->countryRef->id,
                    'name' => $p->countryRef->name,
                    'iso2' => $p->countryRef->iso2,
                ] : null,
                'club' => $p->clubRel ? [
                    'name' => $p->clubRel->name,
                    'color_primary' => $p->clubRel->color_primary,
                    'color_secondary' => $p->clubRel->color_secondary,
                    'color_tertiary' => $p->clubRel->color_tertiary,
                ] : null,
            ];
        };

        $payload = [
            'attribute' => [
                'id' => $attribute->id,
                'key' => $attribute->key,
                'label' => $attribute->label,
                'group' => $attribute->group,
                'scope' => $attribute->scope ?? 'both',
            ],
            'players' => [$toApi($pA), $toApi($pB)],
            'duel_id' => $duel->id,
            'matchmaking' => [
                'category' => $matchmaking['category'] ?? null,
                'positional_mode' => $matchmaking['positional_mode'] ?? null,
                'intent' => $matchmaking['intent'] ?? null,
                'tier' => $matchmaking['tier'] ?? null,
                'gap_profile' => $matchmaking['gap_profile'] ?? null,
            ],
        ];

        if ($debug !== null) {
            $payload['debug'] = $debug;
        }

        return $payload;
    }
}
