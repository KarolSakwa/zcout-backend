<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\AttributeController;
use App\Http\Controllers\Api\DuelController;
use App\Http\Controllers\Api\VoteController;
use App\Http\Controllers\Api\AttributeRankingController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\DatabaseController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'zcout-backend',
    ]);
});

Route::post('/players', [PlayerController::class, 'store']);
Route::get('/players/{player}', [PlayerController::class, 'show']);

Route::get('/attributes', [AttributeController::class, 'index']);
Route::get('/attributes/{key}/ranking', [AttributeRankingController::class, 'index']);

Route::get('/duels/next', [DuelController::class, 'next']);

Route::post('/votes', [VoteController::class, 'store']);
Route::post('/votes/direct', [VoteController::class, 'storeDirect']);

Route::get('/rankings/meta', [RankingController::class, 'meta']);
Route::get('/rankings/{attributeKey}', [RankingController::class, 'attribute']);

Route::get('/database/clubs', [DatabaseController::class, 'clubs']);
Route::get('/database/clubs/{slug}', [DatabaseController::class, 'club']);

Route::middleware('auth:sanctum')->post('/auth/claim-anon', function (\Illuminate\Http\Request $request) {
    $anonId = trim((string) $request->header('X-Zcout-Anon'));
    if ($anonId === '') {
        return response()->json(['message' => 'Missing X-Zcout-Anon header.'], 422);
    }

    $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

    $claimed = \Illuminate\Support\Facades\DB::table('votes')
        ->whereNull('user_id')
        ->where('voter_hash', $voterHash)
        ->update(['user_id' => $request->user()->id]);

    return response()->json(['claimed' => (int) $claimed]);
});
Route::post('/votes/direct', [VoteController::class, 'storeDirect'])->middleware('auth:sanctum');
Route::post('/duels/skip', [\App\Http\Controllers\Api\DuelController::class, 'skip']);
