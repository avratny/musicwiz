<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Neu gesetztes Cover im Cover-Speicher (Original, Name = sha1 des Inhalts) – Maße, Fingerabdruck, Herkunft. */
class CoverImage extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'hash';

    protected $keyType = 'string';

    protected $guarded = [];
}
