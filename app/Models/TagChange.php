<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Änderung eines Tag-Felds in der Datenbank: manuell oder vom System. Abfragen sehen nur offene (noch nicht in
 * die Datei geschrieben); geschriebene bleiben als Verlauf (written_at) – erreichbar über TagChange::history().
 */
class TagChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['written_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope('open', fn (Builder $q) => $q->whereNull($q->qualifyColumn('written_at')));
    }

    /** alle Änderungen, auch die schon geschriebenen */
    public static function history(): Builder
    {
        return static::withoutGlobalScope('open');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
