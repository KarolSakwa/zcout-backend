<?php

namespace App\Actions;

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
                'name' => $p->name,
                'slug' => $p->slug,
                'number' => $p->number,
                'position' => $p->positionRef?->short_label
                    ?? $p->positionRef?->key
                    ?? $p->positionRef?->label
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
