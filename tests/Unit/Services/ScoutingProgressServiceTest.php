<?php

namespace Tests\Unit\Services;

use App\Data\Scouting\ScoutingVoterScope;
use App\Models\User;
use App\Services\Scouting\ScoutingVoterScopeQuery;
use App\Services\Scouting\ScoutingProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ScoutingProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_votes_query_limits_sources_to_duel_and_direct(): void
    {
        $user = User::factory()->create();
        $scope = new ScoutingVoterScope(userId: $user->id, anonVoterHash: null);
        $query = app(ScoutingVoterScopeQuery::class)->votes($scope);

        $this->assertStringContainsString('"source" in (?, ?)', $query->toSql());
        $this->assertContains('duel', $query->getBindings());
        $this->assertContains('direct', $query->getBindings());
    }

    public function test_invalid_config_rejects_second_threshold_not_greater_than_first(): void
    {
        config([
            'scouting.my_scouting_unlock' => 25,
            'scouting.your_impact_unlock' => 25,
        ]);

        $scope = new ScoutingVoterScope(userId: null, anonVoterHash: 'hash');

        $this->expectException(InvalidArgumentException::class);

        app(ScoutingProgressService::class)->buildProgress($scope);
    }

    public function test_invalid_config_rejects_non_positive_first_threshold(): void
    {
        config([
            'scouting.my_scouting_unlock' => 0,
            'scouting.your_impact_unlock' => 125,
        ]);

        $scope = new ScoutingVoterScope(userId: null, anonVoterHash: 'hash');

        $this->expectException(InvalidArgumentException::class);

        app(ScoutingProgressService::class)->buildProgress($scope);
    }
}
