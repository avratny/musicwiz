<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Schreibweisen-Regel: „various“ (→ Various Artists) oder „word“ (Suchtext → Ersetzung, z. B. „d.r.“ → „Dr.“). */
class NameRule extends Model
{
    public const KINDS = ['various' => 'Various Artists', 'word' => 'Namen'];

    protected $guarded = [];

    protected $casts = ['whole_word' => 'boolean'];
}
