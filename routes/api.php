<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\MediaController;
use App\Http\Middleware\ApiToken;
use Illuminate\Support\Facades\Route;

// Schnittstelle für Analyse und Aktionen von außen (z. B. eine KI) – offen; mit API_TOKEN alles außer der Übersicht nur mit Token
Route::get('/', [ApiController::class, 'index']);

Route::middleware(ApiToken::class)->group(function () {
    Route::get('/status', [ApiController::class, 'status']);
    Route::get('/logs', [ApiController::class, 'logs']);
    Route::get('/tracks', [ApiController::class, 'tracks']);
    Route::get('/tracks/{track}', [ApiController::class, 'track']);
    Route::post('/tracks/{track}', [ApiController::class, 'updateTrack']);
    Route::post('/tracks/{track}/revert', [ApiController::class, 'revertTrack']);
    Route::get('/changes', [ApiController::class, 'changes']);
    Route::get('/artists', [ApiController::class, 'artists']);
    Route::get('/artists/{artist}', [ApiController::class, 'artist']);
    Route::match(['get', 'post'], '/sql', [ApiController::class, 'sql']);
    Route::post('/activity/{key}/stop', [MediaController::class, 'stopActivity']);
    Route::match(['get', 'post'], '/ui/{component}', [ApiController::class, 'ui']);
});
