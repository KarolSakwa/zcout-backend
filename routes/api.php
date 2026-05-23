<?php

use App\Http\Controllers\Api\AttributeController;
use App\Http\Controllers\Api\AttributeRankingController;
use App\Http\Controllers\Api\DatabaseController;
use App\Http\Controllers\Api\DuelController;
use App\Http\Controllers\Api\EventLogController;
use App\Http\Controllers\Api\LiveFeedController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'zcout-backend',
    ]);
});

Route::prefix('players')->group(function () {
    Route::get('/{player}', [PlayerController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/{player}/scout-report-attributes', [VoteController::class, 'scoutReportAttributes']);
    });
});

Route::prefix('attributes')->group(function () {
    Route::get('/', [AttributeController::class, 'index']);
    Route::get('/{key}/ranking', [AttributeRankingController::class, 'index']);
});

Route::prefix('duels')->group(function () {
    Route::get('/next', [DuelController::class, 'next']);
    Route::post('/skip', [DuelController::class, 'skip']);
});

Route::prefix('votes')->group(function () {
    Route::post('/', [VoteController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/direct', [VoteController::class, 'storeDirect']);
    });
});

Route::prefix('rankings')->group(function () {
    Route::get('/meta', [RankingController::class, 'meta']);
    Route::get('/{attributeKey}', [RankingController::class, 'attribute']);
});

Route::prefix('database')->group(function () {
    Route::get('/clubs', [DatabaseController::class, 'clubs']);
    Route::get('/clubs/{slug}', [DatabaseController::class, 'club']);
});

Route::prefix('live')->group(function () {
    Route::get('/recent-votes', [LiveFeedController::class, 'recentVotes']);
    Route::get('/top-movers', [LiveFeedController::class, 'topMovers']);
    Route::get('/top-movers-summary', [LiveFeedController::class, 'topMoversSummary']);
});

Route::get('/search', [SearchController::class, 'index']);

Route::post('/log-event', [EventLogController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/scout-reports', [VoteController::class, 'submitScoutReport']);

    Route::post('/auth/claim-anon', function (Request $request) {
        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        if ($anonId === '') {
            return response()->json([
                'message' => 'Missing X-Zcout-Anon header.',
            ], 422);
        }

        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $claimed = DB::table('votes')
            ->whereNull('user_id')
            ->where('voter_hash', $voterHash)
            ->update([
                'user_id' => $request->user()->id,
            ]);

        return response()->json([
            'claimed' => (int) $claimed,
        ]);
    });
});
