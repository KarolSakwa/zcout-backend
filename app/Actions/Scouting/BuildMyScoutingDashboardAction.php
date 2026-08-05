<?php

namespace App\Actions\Scouting;

use App\Data\Scouting\ScoutingVoterScope;
use App\Models\ScoutReportSubmission;
use App\Services\Scouting\ScoutingProgressService;
use App\Services\Scouting\ScoutingVoterScopeQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BuildMyScoutingDashboardAction
{
    public function __construct(
        private readonly ScoutingProgressService $scoutingProgressService,
        private readonly ScoutingVoterScopeQuery $scoutingVoterScopeQuery,
    ) {
    }

    /**
     * @return array{
     *     scouting_progress: array<string, mixed>,
     *     stats: array<string, int>|null,
     *     recent_contributions: list<array<string, mixed>>
     * }
     */
    public function execute(ScoutingVoterScope $scope): array
    {
        $progress = $this->scoutingProgressService->buildProgress($scope);

        if (! $progress->myScoutingUnlocked) {
            return [
                'scouting_progress' => $progress->toArray(),
                'stats' => null,
                'recent_contributions' => [],
            ];
        }

        return [
            'scouting_progress' => $progress->toArray(),
            'stats' => $this->buildStats($scope),
            'recent_contributions' => $this->buildRecentContributions($scope),
        ];
    }

    /**
     * @return array{duels: int, players_rated: int, scout_reports: int}
     */
    private function buildStats(ScoutingVoterScope $scope): array
    {
        return [
            'duels' => $this->countDuels($scope),
            'players_rated' => $this->countPlayersRated($scope),
            'scout_reports' => $this->countScoutReports($scope),
        ];
    }

    private function countDuels(ScoutingVoterScope $scope): int
    {
        return $this->scoutingVoterScopeQuery
            ->votes($scope)
            ->where('source', 'duel')
            ->count();
    }

    private function countPlayersRated(ScoutingVoterScope $scope): int
    {
        $scopedVotes = $this->scoutingVoterScopeQuery->votes($scope);

        $duelSideA = (clone $scopedVotes)
            ->where('source', 'duel')
            ->select('player_a_id as player_id');

        $duelSideB = (clone $scopedVotes)
            ->where('source', 'duel')
            ->whereNotNull('player_b_id')
            ->select('player_b_id as player_id');

        $directPlayers = (clone $scopedVotes)
            ->where('source', 'direct')
            ->select('player_a_id as player_id');

        $union = $duelSideA->union($duelSideB)->union($directPlayers);

        return (int) DB::query()
            ->fromSub($union, 'rated_players')
            ->distinct()
            ->count('player_id');
    }

    private function countScoutReports(ScoutingVoterScope $scope): int
    {
        if ($scope->userId === null) {
            return 0;
        }

        return ScoutReportSubmission::query()
            ->where('user_id', $scope->userId)
            ->where('ratings_count', '>', 0)
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRecentContributions(ScoutingVoterScope $scope): array
    {
        $duelItems = $this->fetchRecentDuelContributions($scope);
        $reportItems = $this->fetchRecentScoutReportContributions($scope);

        return $duelItems
            ->concat($reportItems)
            ->sortByDesc(fn (array $item) => $item['created_at'])
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchRecentDuelContributions(ScoutingVoterScope $scope): Collection
    {
        $scopedIds = $this->scoutingVoterScopeQuery
            ->votes($scope)
            ->where('source', 'duel')
            ->select('id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5);

        $rows = DB::table('votes as v')
            ->joinSub($scopedIds, 'scoped_votes', 'scoped_votes.id', '=', 'v.id')
            ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->join('players as player_a', 'player_a.id', '=', 'v.player_a_id')
            ->join('players as player_b', 'player_b.id', '=', 'v.player_b_id')
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->get([
                'v.id',
                'v.winner_id',
                'v.player_a_id',
                'v.player_b_id',
                'v.pre_rating_a',
                'v.post_rating_a',
                'v.pre_rating_b',
                'v.post_rating_b',
                'v.created_at',
                'a.key as attribute_key',
                DB::raw('COALESCE(player_a.manual_display_name, player_a.fd_name, player_a.name) as player_a_name'),
                DB::raw('COALESCE(player_b.manual_display_name, player_b.fd_name, player_b.name) as player_b_name'),
            ]);

        return $rows->map(function ($row): array {
            return [
                'type' => 'duel',
                'id' => 'vote-'.$row->id,
                'attribute_key' => (string) $row->attribute_key,
                'created_at' => $row->created_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String()
                    : null,
                'selected_player_id' => (int) $row->winner_id,
                'player_a' => [
                    'id' => (int) $row->player_a_id,
                    'name' => (string) $row->player_a_name,
                    'delta' => $this->computeDelta($row->pre_rating_a, $row->post_rating_a),
                ],
                'player_b' => [
                    'id' => (int) $row->player_b_id,
                    'name' => (string) $row->player_b_name,
                    'delta' => $this->computeDelta($row->pre_rating_b, $row->post_rating_b),
                ],
            ];
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchRecentScoutReportContributions(ScoutingVoterScope $scope): Collection
    {
        if ($scope->userId === null) {
            return collect();
        }

        $rows = ScoutReportSubmission::query()
            ->from('scout_report_submissions as s')
            ->join('players as p', 'p.id', '=', 's.player_id')
            ->where('s.user_id', $scope->userId)
            ->where('s.ratings_count', '>', 0)
            ->orderByDesc('s.created_at')
            ->limit(5)
            ->get([
                's.id',
                's.player_id',
                's.ratings_count',
                's.pre_overall',
                's.post_overall',
                's.created_at',
                DB::raw('COALESCE(p.manual_display_name, p.fd_name, p.name) as player_name'),
            ]);

        return $rows->map(function ($row): array {
            $preOverall = $row->pre_overall !== null ? (float) $row->pre_overall : null;
            $postOverall = $row->post_overall !== null ? (float) $row->post_overall : null;

            return [
                'type' => 'scout_report',
                'id' => (string) $row->id,
                'ratings_count' => (int) $row->ratings_count,
                'created_at' => $row->created_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String()
                    : null,
                'player' => [
                    'id' => (int) $row->player_id,
                    'name' => (string) $row->player_name,
                ],
                'overall_before' => $preOverall,
                'overall_after' => $postOverall,
                'overall_delta' => $this->computeDelta($preOverall, $postOverall),
            ];
        });
    }

    private function computeDelta(mixed $pre, mixed $post): ?float
    {
        if ($pre === null || $post === null) {
            return null;
        }

        return round((float) $post - (float) $pre, 2);
    }
}
