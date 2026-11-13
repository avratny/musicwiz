<?php

use App\Http\Controllers\MediaController;
use App\Livewire\AlbumList;
use App\Livewire\ArtistList;
use App\Livewire\ChangeLog;
use App\Livewire\GenreBrowser;
use App\Livewire\MiscPage;
use App\Livewire\SettingsPage;
use App\Livewire\TrackTable;
use App\Livewire\WritePage;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inbox');

Route::get('/cover/{track}/{size?}', [MediaController::class, 'cover'])->whereNumber('size')->name('cover');
Route::get('/cover-image/{hash}/{size?}', [MediaController::class, 'coverImage'])->where('hash', '[0-9a-f]{40}')->whereNumber('size')->name('cover.image');
Route::get('/folder-image/{image}/{size?}', [MediaController::class, 'folderImage'])->whereNumber('size')->name('folder.image');
Route::get('/artist-image/{artist}/{size?}', [MediaController::class, 'artistImage'])->whereNumber('size')->name('artist.image');
Route::get('/audio/{track}', [MediaController::class, 'audio'])->name('audio');
Route::get('/activity', [MediaController::class, 'activity'])->name('activity');
Route::post('/activity/{key}/stop', [MediaController::class, 'stopActivity'])->name('activity.stop');
Route::get('/artist-preview/{image}', [MediaController::class, 'artistPreview'])->name('artist.preview');

Route::livewire('/interpreten', ArtistList::class)->name('artists');
Route::redirect('/artists', '/interpreten');
Route::livewire('/alben', AlbumList::class)->name('albums');
Route::livewire('/genres', GenreBrowser::class)->name('genres');
Route::livewire('/sonstiges', MiscPage::class)->name('misc');
Route::livewire('/aenderungen', ChangeLog::class)->name('changes');
Route::livewire('/schreiben', WritePage::class)->name('write');
Route::livewire('/einstellungen', SettingsPage::class)->name('settings');

// die vier Bereiche: gleiche Seite, anderer Ordner
Route::livewire('/{slug}', TrackTable::class)->whereIn('slug', ['inbox', 'lib', 'blackhole', 'papierkorb'])->name('location');
