<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bild eines Treffers (Original-Adresse, Vorschau lokal). */
class ArtistImage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ArtistSource::class, 'artist_source_id');
    }

    public function previewPath(): ?string
    {
        return $this->preview ? config('musicwiz.preview_dir').'/'.$this->preview : null;
    }
}
