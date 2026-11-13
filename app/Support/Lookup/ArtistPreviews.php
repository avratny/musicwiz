<?php

namespace App\Support\Lookup;

use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\ArtistSource;
use App\Support\Covers;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vorschaubilder der Abgleich-Treffer (höchstens 300 px) lokal ablegen – die Originale erst, wenn ein Bild gewählt
 * wird. Geladen im Hintergrund (FetchPreviews, eigener Worker) und sofort, wenn eins angezeigt wird, das noch fehlt.
 */
class ArtistPreviews
{
    public function __construct(private Client $client) {}

    /** Fehlende Vorschauen eines Interpreten laden (noch nie geladen oder Datei verschwunden). Gibt die Zahl zurück. */
    public function forArtist(Artist $artist): int
    {
        $images = ArtistImage::whereIn('artist_source_id', ArtistSource::where('artist_id', $artist->id)->select('id'))->get()
            ->filter(fn (ArtistImage $i) => ! self::exists($i));

        return $images->filter(fn (ArtistImage $i) => $this->fetch($i, pace: true))->count();
    }

    /** Eine Vorschau laden – im Hintergrund im gemeinsamen Tempo, beim Anzeigen sofort ($pace = false) */
    public function fetch(ArtistImage $image, bool $pace = false): bool
    {
        $dir = config('musicwiz.preview_dir');
        @mkdir($dir, 0775, true);
        try {
            if ($pace) {
                $this->client->pace('images');
            }
            $response = Http::timeout(20)->withHeaders(['User-Agent' => Client::USER_AGENT])->get($image->preview_url ?: $image->url);
            if (! $response->successful()) {
                return false;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'mwprev');
            file_put_contents($tmp, $response->body());
            $file = $image->id.'.jpg';
            $ok = Covers::resize($tmp, $dir.'/'.$file, 300);
            @unlink($tmp);
            if ($ok) {
                $image->update(['preview' => $file]);
            }

            return $ok;
        } catch (Throwable) {
            return false;   // einzelnes Bild nicht erreichbar: beim nächsten Mal erneut
        }
    }

    public static function exists(ArtistImage $image): bool
    {
        return $image->preview && is_file($image->previewPath());
    }
}
