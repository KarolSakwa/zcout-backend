<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlayerProfileShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_goalkeeper_profile_attributes_in_config_order_and_with_config_metadata(): void
    {
        $positionId = $this->createPosition('GK');

        $playerId = $this->createPlayer([
            'name' => 'Gianluigi Donnarumma',
            'slug' => 'gianluigi-donnarumma',
            'position_id' => $positionId,
        ]);

        $attributeIds = [
            'gk_rushing_out' => $this->createAttribute([
                'key' => 'gk_rushing_out',
                'label' => 'Rushing Out',
                'group' => 'SWEEPER',
                'scope' => 'gk',
            ]),
            'gk_kicking' => $this->createAttribute([
                'key' => 'gk_kicking',
                'label' => 'Passing',
                'group' => 'DISTRIBUTION',
                'scope' => 'gk',
            ]),
            'pace' => $this->createAttribute([
                'key' => 'pace',
                'label' => 'Pace',
                'group' => 'PACE',
                'scope' => 'both',
            ]),
            'gk_command_of_area' => $this->createAttribute([
                'key' => 'gk_command_of_area',
                'label' => 'Command of Area',
                'group' => 'GOALKEEPING',
                'scope' => 'gk',
            ]),
            'gk_reflexes' => $this->createAttribute([
                'key' => 'gk_reflexes',
                'label' => 'Reflexes',
                'group' => 'GOALKEEPING',
                'scope' => 'gk',
            ]),
        ];

        $this->seedRatingRows($playerId, $attributeIds);

        $response = $this->getJson("/api/players/{$playerId}")
            ->assertOk();

        $attributes = collect($response->json('attributes'));
        $attributesByKey = $attributes->keyBy('key');

        $this->assertSame([
            'pace',
            'gk_reflexes',
            'gk_command_of_area',
            'gk_kicking',
            'gk_rushing_out',
        ], $attributes->pluck('key')->all());

        $this->assertSame('SHOT_STOPPING', $attributesByKey->get('gk_reflexes')['group']);
        $this->assertSame('AERIAL', $attributesByKey->get('gk_command_of_area')['group']);
        $this->assertSame('Kicking', $attributesByKey->get('gk_kicking')['label']);
        $this->assertSame('DISTRIBUTION', $attributesByKey->get('gk_kicking')['group']);
        $this->assertSame('RUSHING_OUT', $attributesByKey->get('gk_rushing_out')['group']);
        $this->assertFalse($attributes->pluck('key')->contains('passing'));
    }

    public function test_returns_outfield_profile_attributes_in_config_order_and_with_config_metadata(): void
    {
        $positionId = $this->createPosition('CM');

        $playerId = $this->createPlayer([
            'name' => 'Habib Diarra',
            'slug' => 'habib-diarra',
            'position_id' => $positionId,
        ]);

        $attributeIds = [
            'marking' => $this->createAttribute([
                'key' => 'marking',
                'label' => 'Marking',
                'group' => 'DEFENSIVE',
                'scope' => 'both',
            ]),
            'strength' => $this->createAttribute([
                'key' => 'strength',
                'label' => 'Strength',
                'group' => 'PHYSICAL',
                'scope' => 'both',
            ]),
            'leadership' => $this->createAttribute([
                'key' => 'leadership',
                'label' => 'Leadership',
                'group' => 'MENTAL',
                'scope' => 'both',
            ]),
            'passing' => $this->createAttribute([
                'key' => 'passing',
                'label' => 'Passing',
                'group' => 'PASSING',
                'scope' => 'both',
            ]),
            'finishing' => $this->createAttribute([
                'key' => 'finishing',
                'label' => 'Finishing',
                'group' => 'OFFENSIVE',
                'scope' => 'both',
            ]),
            'ball_control' => $this->createAttribute([
                'key' => 'ball_control',
                'label' => 'Ball Control',
                'group' => 'TECHNIQUE',
                'scope' => 'both',
            ]),
        ];

        $this->seedRatingRows($playerId, $attributeIds);

        $response = $this->getJson("/api/players/{$playerId}")
            ->assertOk();

        $attributes = collect($response->json('attributes'));
        $attributesByKey = $attributes->keyBy('key');

        $this->assertSame([
            'ball_control',
            'finishing',
            'passing',
            'leadership',
            'strength',
            'marking',
        ], $attributes->pluck('key')->all());

        $this->assertSame('TECHNIQUE', $attributesByKey->get('ball_control')['group']);
        $this->assertSame('ATTACK', $attributesByKey->get('finishing')['group']);
        $this->assertSame('MENTALITY', $attributesByKey->get('leadership')['group']);
        $this->assertSame('PHYSICALITY', $attributesByKey->get('strength')['group']);
        $this->assertSame('DEFENCE', $attributesByKey->get('marking')['group']);
        $this->assertFalse($attributes->pluck('key')->contains('gk_kicking'));
    }

    private function createPosition(string $shortLabel): int
    {
        return DB::table('positions')->insertGetId([
            'key' => strtolower($shortLabel),
            'label' => $shortLabel,
            'short_label' => $shortLabel,
        ]);
    }

    private function createPlayer(array $overrides = []): int
    {
        return DB::table('players')->insertGetId(array_merge([
            'name' => 'Test Player',
            'slug' => Str::uuid()->toString(),
            'position_id' => null,
            'club_id' => null,
            'country_id' => null,
            'number' => null,
            'date_of_birth' => '2000-01-01',
            'fd_name' => null,
            'fd_number' => null,
            'manual_display_name' => null,
            'manual_number' => null,
        ], $overrides));
    }

    private function createAttribute(array $overrides = []): int
    {
        return DB::table('attributes')->insertGetId(array_merge([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'scope' => 'both',
        ], $overrides));
    }

    private function seedRatingRows(int $playerId, array $attributeIds): void
    {
        $rows = collect($attributeIds)
            ->values()
            ->map(fn (int $attributeId, int $index) => [
                'player_id' => $playerId,
                'attribute_id' => $attributeId,
                'rating' => 60 + $index,
                'confidence' => 50,
                'rating_weight_sum' => 1,
                'confidence_weight_sum' => 1,
                'votes_count' => 1,
                'last_vote_at' => now(),
            ])
            ->all();

        DB::table('player_attribute_ratings')->insert($rows);
    }
}
