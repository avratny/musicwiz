<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eine Datei-Aktion beim Schreiben (Protokoll): move, copy, trash, extra (Bild/Beiwerk mitgezogen), delete, rmdir. */
class FileOperation extends Model
{
    protected $guarded = [];

    public const UPDATED_AT = null;
}
