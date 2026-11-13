<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Album von einer Prüfung ausgenommen (z. B. gewollt unvollständig). */
class AlbumExemption extends Model
{
    protected $table = 'album_exemptions';

    protected $guarded = [];

    public const UPDATED_AT = null;
}
