<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vorschläge von Claude für einen kaputten Wert (Encoding-Fehler) bzw. „Passt so“ beim Prüfen der Schreibweise – Schlüssel: sha1 des Werts. */
class TextRepair extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['suggestions' => 'array', 'asked_at' => 'datetime', 'accepted_at' => 'datetime'];
}
