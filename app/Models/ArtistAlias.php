<?php

namespace App\Models;

use App\Support\ArtistNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistAlias extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => ArtistNames::flushProtected());
        static::deleted(fn () => ArtistNames::flushProtected());
        static::saving(fn (ArtistAlias $a) => $a->compare_key = ArtistNames::compareKey($a->alias));
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
