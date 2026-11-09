<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// Schnittstelle zum Prüfen und Vormerken (ohne Anmeldung, nur im eigenen Netz) – geschrieben wird nur in der App
Route::get('/', [ApiController::class, 'index']);
Route::get('/stats', [ApiController::class, 'stats']);
Route::get('/checks', [ApiController::class, 'checks']);
Route::get('/checks/{key}', [ApiController::class, 'check']);
Route::post('/checks/{key}/stage', [ApiController::class, 'stageFindings']);
Route::get('/pending', [ApiController::class, 'pending']);
Route::post('/pending/stage', [ApiController::class, 'stage']);
Route::post('/pending/discard', [ApiController::class, 'discard']);
Route::get('/albums', [ApiController::class, 'albums']);
Route::get('/albums/{album}', [ApiController::class, 'album']);
Route::get('/tracks', [ApiController::class, 'tracks']);
Route::get('/artists', [ApiController::class, 'artists']);
Route::get('/history', [ApiController::class, 'history']);
Route::get('/history/{operation}', [ApiController::class, 'historyEntry']);
Route::get('/status', [ApiController::class, 'status']);
Route::get('/logs', [ApiController::class, 'logs']);
