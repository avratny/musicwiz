<?php

use App\Http\Controllers\MediaController;
use App\Livewire;
use App\Support\SettingsBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inbox');

Route::livewire('/inbox', Livewire\Inbox::class)->name('inbox');
Route::livewire('/inbox/{album}', Livewire\AlbumEditor::class)->name('inbox.album');
Route::livewire('/lib', Livewire\Library::class)->name('library');
Route::livewire('/lib/album/{album}', Livewire\AlbumEditor::class)->name('library.album');
Route::livewire('/blackhole', Livewire\Blackhole::class)->name('blackhole');
Route::redirect('/bestand', '/lib');
Route::livewire('/youtube', Livewire\YouTubeDownload::class)->name('youtube');
Route::livewire('/papierkorb', Livewire\TrashPage::class)->name('trash');
Route::livewire('/kuenstler', Livewire\Artists::class)->name('artists');
Route::livewire('/aufraeumen', Livewire\Cleanup::class)->name('cleanup');
Route::livewire('/aenderungen', Livewire\PendingPage::class)->name('pending');
Route::livewire('/aufraeumen/zeichen', Livewire\TextRepairAssistant::class)->name('text-repair');
Route::livewire('/verlauf', Livewire\History::class)->name('history');
Route::livewire('/statistik', Livewire\Statistics::class)->name('statistics');
Route::livewire('/flac-kandidaten', Livewire\FlacCandidatesPage::class)->name('flac-candidates');
Route::livewire('/einstellungen', Livewire\SettingsPage::class)->name('settings');

Route::get('/media/cover/album/{album}', [MediaController::class, 'albumCover'])->name('cover.album');
Route::get('/media/cover/artist/{artist}', [MediaController::class, 'artistImage'])->name('cover.artist');
Route::get('/media/cover/tmp', [MediaController::class, 'tmpCover'])->name('cover.tmp');
Route::get('/media/trash', [MediaController::class, 'trashFile'])->name('trash.file');
Route::get('/einstellungen/export', function (Request $request, SettingsBackup $backup) {
    $stamp = now()->format('Y-m-d-His');
    if ($request->boolean('images', true)) {
        $zip = $backup->exportZip($request->boolean('secrets'), true);

        return response()->download($zip, 'musicwiz-sicherung-'.$stamp.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
    $json = json_encode($backup->export($request->boolean('secrets')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return response($json, 200, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="musicwiz-einstellungen-'.$stamp.'.json"',
    ]);
})->name('settings.export');
Route::get('/media/audio/{track}', [MediaController::class, 'audio'])->name('audio');
Route::get('/media/rating/{track}', [MediaController::class, 'rating'])->name('rating');
Route::post('/media/rating/{track}', [MediaController::class, 'rate'])->name('rating.set');
