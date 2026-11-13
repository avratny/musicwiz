<?php

namespace App\Http\Controllers;

use App\Jobs\AskClaudeTextRepairs;
use App\Jobs\CheckComboNames;
use App\Jobs\CheckRecordings;
use App\Jobs\ConfirmClearArtists;
use App\Jobs\FetchLyrics;
use App\Jobs\LookupArtists;
use App\Jobs\MeasureFingerprints;
use App\Jobs\MeasureReplayGain;
use App\Jobs\WriteChanges;
use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\Covers;
use App\Support\CoverStore;
use App\Support\Locations;
use App\Support\Lookup\ArtistPreviews;
use App\Support\MusicBrainzData;
use App\Support\OpenCounts;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function cover(Track $track, Covers $covers, int $size = 300): BinaryFileResponse
    {
        $file = $covers->thumbnail($track, $size, request()->boolean('datei'));
        abort_unless($file, 404);
        // mit Versionszusatz (Covers::url) lange behalten – ohne (z. B. Player) jedes Mal nachfragen (304, falls gleich)
        $cache = request()->filled('v') ? 'public, max-age='.Covers::BROWSER_CACHE : 'no-cache';
        $response = response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => $cache]);
        $response->setEtag(md5($file));
        $response->isNotModified(request());

        return $response;
    }

    /** neu gesetztes Cover aus dem Cover-Speicher (Adresse = Inhalt, darf lange zwischengespeichert werden) */
    public function coverImage(string $hash, CoverStore $store, int $size = 300): BinaryFileResponse
    {
        $file = $store->thumbnail($hash, $size);
        abort_unless($file, 404);

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /** Bild im Ordner (cover/folder/front), verkleinert und zwischengespeichert */
    public function folderImage(FolderImage $image, int $size = 300): BinaryFileResponse
    {
        $size = in_array($size, Covers::SIZES, true) ? $size : 300;
        $target = config('musicwiz.cover_cache').'/folder-'.sha1($image->path.'|'.$image->mtime.'|'.$image->size).'-'.$size.'.jpg';
        if (! is_file($target)) {
            abort_unless(is_file($image->path), 404);
            @mkdir(dirname($target), 0775, true);
            abort_unless(Covers::resize($image->path, $target, $size), 404);
        }

        // Adresse mit Stand (?v=), siehe Aufrufer
        return response()->file($target, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age='.Covers::BROWSER_CACHE]);
    }

    /** Künstlerbild, verkleinert und zwischengespeichert */
    public function artistImage(Artist $artist, int $size = 300): BinaryFileResponse
    {
        $size = in_array($size, Covers::SIZES, true) ? $size : 300;
        $source = $artist->imagePath();
        abort_unless($source, 404);
        // Datei und Stand im Namen: gleiche ID mit anderem Bild (z. B. nach neuem Aufbau der Datenbank) trifft nie das alte
        $target = config('musicwiz.cover_cache').'/artist-'.$artist->id.'-'.hash('crc32b', $source).filemtime($source).'-'.$size.'.jpg';
        if (! is_file($target)) {
            @mkdir(dirname($target), 0775, true);
            abort_unless(Covers::resize($source, $target, $size), 404);
        }

        // Adresse mit Stand (?v= Änderungszeit des Interpreten)
        return response()->file($target, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age='.Covers::BROWSER_CACHE]);
    }

    /** Vorschaubild aus dem Abgleich (lokal gespeichert) – fehlt es noch (Hintergrund nicht so weit), sofort laden */
    public function artistPreview(ArtistImage $image, ArtistPreviews $previews): BinaryFileResponse
    {
        abort_unless(ArtistPreviews::exists($image) || $previews->fetch($image), 404);
        $path = $image->fresh()->previewPath();

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age='.Covers::BROWSER_CACHE]);
    }

    /** Hintergrundvorgang anhalten (Abgleich, Prüfung der Kombis, MusicBrainz-Daten) */
    public function stopActivity(string $key): JsonResponse
    {
        match ($key) {
            'lookup' => LookupArtists::stop(),
            CheckComboNames::KEY => CheckComboNames::stop(),
            MusicBrainzData::ACTIVITY => MusicBrainzData::stop(),
            ConfirmClearArtists::KEY => ConfirmClearArtists::stop(),
            CheckRecordings::KEY => CheckRecordings::stop(),
            AskClaudeTextRepairs::KEY => AskClaudeTextRepairs::stop(),
            MeasureReplayGain::KEY => MeasureReplayGain::stop(),
            MeasureFingerprints::KEY => MeasureFingerprints::stop(),
            WriteChanges::KEY => WriteChanges::stop(),
            FetchLyrics::KEY => FetchLyrics::stop(),
            default => abort(404),
        };

        return response()->json(['ok' => true]);
    }

    /** Abspielen: Datei mit Range-Unterstützung (Springen im Lied). */
    public function audio(Track $track): BinaryFileResponse
    {
        abort_unless(is_file($track->path), 404);
        $types = ['mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav', 'aiff' => 'audio/aiff', 'wma' => 'audio/x-ms-wma'];

        return response()->file($track->path, ['Content-Type' => $types[$track->ext] ?? 'application/octet-stream']);
    }

    /** Fortschritt und Anzahl je Bereich (Abfrage aus der Oberfläche). */
    public function activity(): JsonResponse
    {
        return response()->json(['activities' => Activity::visible(), 'counts' => Locations::counts() + app(OpenCounts::class)->nav() + ['changes' => TagChange::count()]]);
    }
}
