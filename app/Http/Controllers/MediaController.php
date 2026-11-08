<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\Covers;
use App\Support\Ratings;
use App\Support\Settings;
use App\Support\TagTool;
use App\Support\Trash;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function albumCover(Album $album, Request $request, Covers $covers)
    {
        $path = $covers->albumThumb($album, (int) $request->query('s', 400));
        abort_unless($path && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=604800']);
    }

    /** Bewertung fürs Player-Fenster: aktueller Wert und ob bewertet werden darf (nicht im Blackhole, nur MP3/FLAC). */
    public function rating(Track $track)
    {
        return response()->json(['rating' => (int) $track->rating, 'rateable' => self::rateable($track)]);
    }

    public function rate(Track $track, Request $request, Ratings $ratings)
    {
        abort_unless(self::rateable($track), 422, 'Dieser Titel kann nicht bewertet werden.');
        $stars = (int) $request->validate(['rating' => 'required|integer|min:0|max:5'])['rating'];
        $ratings->track($track, $stars);

        return response()->json(['rating' => $stars, 'rateable' => true]);
    }

    private static function rateable(Track $track): bool
    {
        return $track->location !== 'blackhole' && TagTool::canWrite($track->path);
    }

    public function artistImage(Artist $artist, Settings $settings)
    {
        $path = rtrim($settings->get('artist_images'), '/').'/'.$artist->image;
        abort_unless($artist->image && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** Reinhören per HTML5-Audio (BinaryFileResponse unterstützt Range-Anfragen). */
    public function audio(Track $track)
    {
        abort_unless(is_file($track->path), 404);
        $mime = match ($track->ext) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'ogg', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };

        return response()->file($track->path, ['Content-Type' => $mime]);
    }

    /** Datei aus dem Papierkorb abspielen bzw. anzeigen (nur innerhalb des Papierkorb-Ordners). */
    public function trashFile(Request $request, Trash $trash)
    {
        $path = $trash->root().'/'.ltrim((string) $request->query('f'), '/');
        abort_unless(is_file($path) && $trash->contains($path), 404);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav',
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/octet-stream',
        };

        return response()->file($path, ['Content-Type' => $mime]);
    }

    public function tmpCover(Request $request)
    {
        $name = basename((string) $request->query('f'));
        $path = storage_path('app/private/tmp/'.$name);
        abort_unless(preg_match('/^cover-[\w-]+\.jpg$/', $name) && is_file($path), 404);

        return response()->file($path);
    }
}
