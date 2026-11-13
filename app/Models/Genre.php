<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Knoten im Genrebaum: Ebene 1 Hauptgenre, 2 Familie, 3 Style. */
class Genre extends Model
{
    public const LEVELS = [1 => 'Hauptgenre', 2 => 'Familie', 3 => 'Style'];

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(GenreSynonym::class)->orderBy('synonym');
    }
}
