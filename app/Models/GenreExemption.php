<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Album, Sampler oder Einzeltitel von einer Genre-Prüfung ausgenommen („Passt so“). */
class GenreExemption extends Model
{
    protected $table = 'genre_exemptions';

    protected $guarded = [];

    public const UPDATED_AT = null;
}
