<?php

namespace App\Models;

use App\Support\FileOps;
use Illuminate\Database\Eloquent\Model;

/** Vom Nutzer gelöschte Schreibweise eines Künstlers – MusicBrainz darf sie nicht wieder eintragen. */
class RejectedAlias extends Model
{
    protected $guarded = [];

    public static function keyOf(string $alias): string
    {
        return mb_strtolower(FileOps::nfc(trim($alias)));
    }

    public static function remember(int $artistId, string $alias): void
    {
        self::updateOrCreate(['artist_id' => $artistId, 'alias_key' => self::keyOf($alias)], ['alias' => trim($alias)]);
    }

    public static function forget(int $artistId, string $alias): void
    {
        self::where('artist_id', $artistId)->where('alias_key', self::keyOf($alias))->delete();
    }
}
