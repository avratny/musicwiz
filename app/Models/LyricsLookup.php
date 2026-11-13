<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Abfrage eines Lieds bei LRCLIB (Lyrics): genauer Treffer bzw. Kandidaten zur Auswahl */
class LyricsLookup extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'track_id';

    protected $guarded = [];

    protected $casts = ['exact' => 'array', 'candidates' => 'array', 'fetched_at' => 'datetime'];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
