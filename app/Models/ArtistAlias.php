<?php

namespace App\Models;

use App\Support\ArtistCredits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alternative Schreibweise eines Künstlers (so kommt er in Tags vor). */
class ArtistAlias extends Model
{
    protected $guarded = [];

    /** Zerlegen von Kombis hängt an bestätigten Namen, Schreibweisen und Entscheidungen */
    protected static function booted(): void
    {
        static::saved(fn () => app(ArtistCredits::class)->forget());
        static::deleted(fn () => app(ArtistCredits::class)->forget());
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
