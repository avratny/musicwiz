<?php

namespace App\Models;

use App\Support\ArtistCredits;
use Illuminate\Database\Eloquent\Model;

/** Entscheidung zu einer Kombi wie „Beam & Yanou“: trennen (Teile), bleibt ganz, oder ganz + Mitglieder (die Mitglieder). */
class ArtistSplit extends Model
{
    public const SPLIT = 'split';

    public const WHOLE = 'whole';

    public const MEMBERS = 'members';

    protected $guarded = [];

    /** Zerlegen von Kombis hängt an bestätigten Namen, Schreibweisen und Entscheidungen */
    protected static function booted(): void
    {
        static::saved(fn () => app(ArtistCredits::class)->forget());
        static::deleted(fn () => app(ArtistCredits::class)->forget());
    }

    protected $casts = ['parts' => 'array'];
}
