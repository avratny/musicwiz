<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vorgemerkte Änderung eines Titels (siehe App\Support\PendingChanges). */
class PendingChange extends Model
{
    protected $guarded = [];

    protected $casts = ['tags_before' => 'array', 'tags' => 'array', 'labels' => 'array', 'relocate' => 'boolean', 'remove_cover' => 'boolean', 'embed_cover' => 'boolean', 'revision' => 'integer'];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
