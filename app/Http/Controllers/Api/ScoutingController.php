<?php

namespace App\Http\Controllers\Api;

use App\Actions\Scouting\BuildMyScoutingDashboardAction;
use App\Actions\Scouting\ClaimAnonVotesAction;
use App\Actions\Scouting\ResolveScoutingVoterScopeAction;
use App\Data\ActionFailure;
use App\Http\Controllers\Controller;
use App\Services\Scouting\ScoutingProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoutingController extends Controller
{
    public function progress(
        Request $request,
        ResolveScoutingVoterScopeAction $resolveScoutingVoterScopeAction,
        ScoutingProgressService $scoutingProgressService,
    ): JsonResponse {
        $scope = $resolveScoutingVoterScopeAction->execute($request);

        if ($scope instanceof ActionFailure) {
            return response()->json([
                'message' => $scope->message,
            ], $scope->status);
        }

        return response()->json([
            'scouting_progress' => $scoutingProgressService->progressArray($scope),
        ]);
    }

    public function myScouting(
        Request $request,
        ResolveScoutingVoterScopeAction $resolveScoutingVoterScopeAction,
        BuildMyScoutingDashboardAction $buildMyScoutingDashboardAction,
    ): JsonResponse {
        $scope = $resolveScoutingVoterScopeAction->execute($request);

        if ($scope instanceof ActionFailure) {
            return response()->json([
                'message' => $scope->message,
            ], $scope->status);
        }

        return response()->json($buildMyScoutingDashboardAction->execute($scope));
    }

    public function claimAnon(
        Request $request,
        ClaimAnonVotesAction $claimAnonVotesAction,
        ResolveScoutingVoterScopeAction $resolveScoutingVoterScopeAction,
        ScoutingProgressService $scoutingProgressService,
    ): JsonResponse {
        $result = $claimAnonVotesAction->execute($request);

        if (($result['ok'] ?? false) !== true) {
            return response()->json($result['body'], $result['status']);
        }

        $scope = $resolveScoutingVoterScopeAction->execute($request);

        if ($scope instanceof ActionFailure) {
            return response()->json($result['body'], $result['status']);
        }

        return response()->json([
            ...$result['body'],
            'scouting_progress' => $scoutingProgressService->progressArray($scope),
        ], $result['status']);
    }
}
