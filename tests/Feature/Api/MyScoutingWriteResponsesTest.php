<?php

namespace Tests\Feature\Api;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Models\User;
use App\Services\Ranking\AttributeRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyScoutingWriteResponsesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andReturn(['rank' => null, 'is_top_ten' => false]);
        });

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");
    }

    public function test_successful_duel_vote_includes_incremented_scouting_progress(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'write-response-duel-vote';

        $response = $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixture),
            ['X-Zcout-Anon' => $anonId],
        );

        $response
            ->assertOk()
            ->assertJsonStructure([
                'vote_id',
                'duel_id',
                'attribute_id',
                'players',
                'popularity',
                'scouting_progress' => [
                    'contributions',
                    'my_scouting_unlocked',
                    'progress_target',
                    'stage_progress',
                    'stage_target',
                    'next_unlock',
                ],
            ])
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_duplicate_duel_vote_does_not_include_scouting_progress(): void
    {
        $fixture = $this->createDuelFixture();
        $headers = ['X-Zcout-Anon' => 'write-response-duel-duplicate'];
        $payload = $this->duelVotePayload($fixture);

        $this->postJson('/api/votes', $payload, $headers)->assertOk();

        $this->postJson('/api/votes', $payload, $headers)
            ->assertStatus(409)
            ->assertJsonMissing(['scouting_progress']);
    }

    public function test_successful_scout_report_includes_scouting_progress(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutFixture();

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => 'passing', 'value' => 88],
                ['attribute_key' => 'creativity', 'value' => 77],
            ],
            'skipped_attribute_ids' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('votes_created', 2)
            ->assertJsonPath('scouting_progress.contributions', 2);
    }

    public function test_skip_only_scout_report_returns_unchanged_scouting_progress(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutFixture();

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [],
            'skipped_attribute_ids' => [$fixture['creativity_id']],
        ])
            ->assertCreated()
            ->assertJsonPath('votes_created', 0)
            ->assertJsonPath('scouting_progress.contributions', 0);
    }

    private function createScoutFixture(): array
    {
        $unique = uniqid();
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm-'.$unique,
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Scout Player '.$unique,
            'slug' => 'scout-player-'.$unique,
            'position_id' => $positionId,
        ]);

        $passingId = DB::table('attributes')->where('key', 'passing')->value('id')
            ?? DB::table('attributes')->insertGetId([
                'key' => 'passing',
                'label' => 'Passing',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);

        $creativityId = DB::table('attributes')->where('key', 'creativity')->value('id')
            ?? DB::table('attributes')->insertGetId([
                'key' => 'creativity',
                'label' => 'Creativity',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);

        return [
            'player_id' => $playerId,
            'creativity_id' => (int) $creativityId,
        ];
    }

    private function createDuelFixture(): array
    {
        $unique = uniqid();
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm-'.$unique,
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A '.$unique,
            'slug' => 'player-a-'.$unique,
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B '.$unique,
            'slug' => 'player-b-'.$unique,
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->where('key', 'passing')->value('id')
            ?? DB::table('attributes')->insertGetId([
                'key' => 'passing',
                'label' => 'Passing',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'created_at' => now(),
        ]);

        return [
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'duel_id' => $duelId,
        ];
    }

    private function duelVotePayload(array $fixture): array
    {
        return [
            'attribute_key' => 'passing',
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'duel_id' => $fixture['duel_id'],
        ];
    }
}
