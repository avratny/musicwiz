<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Titellisten-Kandidaten eines Albums aus dem Netz – und welcher gewählt ist. */
class AlbumTracklist extends Model
{
    protected $table = 'album_tracklists';

    protected $guarded = [];

    protected $casts = ['candidates' => 'array', 'fetched_at' => 'datetime'];
}
