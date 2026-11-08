<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscSet extends Model
{
    protected $guarded = [];

    protected $casts = ['members' => 'array'];

    /** Set, zu dem ein Ordner gehört. */
    public static function forDir(string $dir): ?self
    {
        return self::all()->first(fn (self $s) => in_array($dir, array_column($s->members, 'dir'), true));
    }
}
