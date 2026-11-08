<?php

namespace App\Models;

use App\Support\FileOps;
use Illuminate\Database\Eloquent\Model;

/** Eine Kombi wie „Yanou & Liz“ ist eine Zusammenarbeit – ArtistNames::split() trennt sie in ihre Teile. */
class ArtistSplit extends Model
{
    protected $guarded = [];

    protected $casts = ['parts' => 'array'];

    public static function keyOf(string $name): string
    {
        return mb_strtolower(FileOps::nfc(trim($name)));
    }

    /** @param  list<string>  $parts */
    public static function remember(string $name, array $parts): self
    {
        return self::updateOrCreate(['name_key' => self::keyOf($name)], ['name' => trim($name), 'parts' => array_values($parts)]);
    }
}
