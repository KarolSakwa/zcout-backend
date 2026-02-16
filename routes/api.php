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


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

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
Route::get('/duels/next', [DuelController::class, 'next']);
Route::post('/votes', [VoteController::class, 'store']);
Route::post('/votes/direct', [VoteController::class, 'storeDirect']);
Route::get('/attributes/{key}/ranking', [AttributeRankingController::class, 'index']);
Route::get('/rankings/meta', [RankingController::class, 'meta']);
Route::get('/rankings/{attributeKey}', [RankingController::class, 'attribute']);
Route::get('/database/clubs', [DatabaseController::class, 'clubs']);
Route::get('/database/clubs/{slug}', [DatabaseController::class, 'club']);
