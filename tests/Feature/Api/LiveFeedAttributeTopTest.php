<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CreatesCurrentPremierLeagueClub;
use Tests\TestCase;

class LiveFeedAttributeTopTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCurrentPremierLeagueClub;

    public function test_it_returns_top_players_for_attribute(): void
    {
        $clubId = $this->createCurrentPremierLeagueClub('Club '.uniqid('pl', true), 'club-'.uniqid('pl', true));

        $playerIds = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta'] as $index => $name) {
            $playerIds[$name] = (int) DB::table('players')->insertGetId([
                'name' => $name,
                'slug' => str($name)->slug()->toString().'-'.uniqid(),
                'club_id' => $clubId,
            ]);
        }

        DB::table('attributes')->insert([
            'key' => 'creativity',
            'label' => 'Creativity',
            'group' => 'TECHNIQUE',
        ]);

        Redis::del('ranking:creativity');
        Redis::zadd('ranking:creativity', 95.0, (string) $playerIds['Alpha']);
        Redis::zadd('ranking:creativity', 93.0, (string) $playerIds['Beta']);
        Redis::zadd('ranking:creativity', 91.0, (string) $playerIds['Gamma']);
        Redis::zadd('ranking:creativity', 89.0, (string) $playerIds['Delta']);
        Redis::zadd('ranking:creativity', 87.0, (string) $playerIds['Epsilon']);
        Redis::zadd('ranking:creativity', 85.0, (string) $playerIds['Zeta']);

        $response = $this->getJson('/api/live/attribute-top?attribute_key=creativity');

        $response
            ->assertOk()
            ->assertJsonPath('attribute.key', 'creativity')
            ->assertJsonCount(5, 'players')
            ->assertJsonPath('players.0.playerId', $playerIds['Alpha'])
            ->assertJsonPath('players.0.rank', 1)
            ->assertJsonPath('players.4.playerId', $playerIds['Epsilon']);
    }

    public function test_it_excludes_duelists_before_reveal(): void
    {
        $clubId = $this->createCurrentPremierLeagueClub('Club '.uniqid('pl', true), 'club-'.uniqid('pl', true));

        $playerIds = [];
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta'] as $name) {
            $playerIds[$name] = (int) DB::table('players')->insertGetId([
                'name' => $name,
                'slug' => str($name)->slug()->toString().'-'.uniqid(),
                'club_id' => $clubId,
            ]);
        }

        DB::table('attributes')->insert([
            'key' => 'creativity',
            'label' => 'Creativity',
            'group' => 'TECHNIQUE',
        ]);

        Redis::del('ranking:creativity');
        Redis::zadd('ranking:creativity', 95.0, (string) $playerIds['Alpha']);
        Redis::zadd('ranking:creativity', 93.0, (string) $playerIds['Beta']);
        Redis::zadd('ranking:creativity', 91.0, (string) $playerIds['Gamma']);
        Redis::zadd('ranking:creativity', 89.0, (string) $playerIds['Delta']);
        Redis::zadd('ranking:creativity', 87.0, (string) $playerIds['Epsilon']);
        Redis::zadd('ranking:creativity', 85.0, (string) $playerIds['Zeta']);

        $exclude = $playerIds['Alpha'].','.$playerIds['Beta'];

        $response = $this->getJson('/api/live/attribute-top?attribute_key=creativity&exclude='.$exclude);

        $response
            ->assertOk()
            ->assertJsonCount(5, 'players')
            ->assertJsonPath('players.0.playerId', $playerIds['Gamma'])
            ->assertJsonPath('players.1.playerId', $playerIds['Delta']);

        $returnedIds = collect($response->json('players'))->pluck('playerId')->all();
        $this->assertNotContains($playerIds['Alpha'], $returnedIds);
        $this->assertNotContains($playerIds['Beta'], $returnedIds);
    }
}
