<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Schreibweise eines Interpreten laut Dienst (MusicBrainz-Alias, Discogs-Namensvariante …) – nur ein Vorschlag. */
class ArtistSourceAlias extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['primary' => 'boolean'];
}
