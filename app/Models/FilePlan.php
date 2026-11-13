<?php

namespace App\Models;

use App\Support\Duplicates;
use App\Support\IncompleteAlbums;
use App\Support\SplitAlbums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vorgemerkte Datei-Aktion: Lied in ein Album kopieren bzw. verschieben – ausgeführt erst beim Schreiben. „values“ ist
 * der Stand beim Vormerken; geschrieben wird currentValues() (das Album kann sich inzwischen geändert haben).
 */
class FilePlan extends Model
{
    protected $table = 'file_plans';

    protected $guarded = [];

    protected $casts = ['values' => 'array'];

    /**
     * Tags fürs Ziel, jetzt berechnet: ersetzt das Lied ein anderes, dessen Tags (Platz und Album, wie es jetzt ist),
     * sonst die Album-Felder des Ziel-Albums mit der vorgemerkten Nummer und CD.
     */
    public function currentValues(): array
    {
        if ($this->replaces_track_id && ($old = Track::find($this->replaces_track_id))) {
            return collect(Duplicates::TAKEN_FIELDS)->mapWithKeys(fn ($f) => [$f => $old->{$f}])->all();
        }
        $tracks = Track::where('album_hash', $this->target_album_hash)->whereIn('location', SplitAlbums::LOCATIONS)->get();

        return $tracks->isEmpty() ? $this->values
            : IncompleteAlbums::albumValues($tracks, max(1, (int) ($this->values['disc'] ?? 1)), (int) $this->values['track']);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
