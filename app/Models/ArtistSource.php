<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein Treffer eines Dienstes für einen Interpreten (Zwischenspeicher des Abgleichs). */
class ArtistSource extends Model
{
    public const SERVICES = ['musicbrainz' => 'MusicBrainz', 'spotify' => 'Spotify', 'discogs' => 'Discogs', 'audiodb' => 'TheAudioDB', 'fanart' => 'Fanart.tv', 'lastfm' => 'Last.fm'];

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'chosen' => 'boolean', 'fetched_at' => 'datetime'];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ArtistSourceAlias::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ArtistImage::class)->orderBy('rank');
    }
}
