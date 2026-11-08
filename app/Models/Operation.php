<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operation extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'finished_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(HistoryEntry::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running']);
    }

    /** Eingeplant, aber noch nicht dran (z. B. Download-Fortsetzung nach erreichter Tagesgrenze). */
    public function isPlanned(): bool
    {
        $at = $this->payload['options']['not_before'] ?? null;

        return $this->status === 'pending' && $at && now()->lt($at);
    }

    public function canUndo(): bool
    {
        return in_array($this->status, ['done', 'failed', 'partly_undone']) && $this->type !== 'undo'
            && $this->entries()->whereNull('undone_at')->exists()
            && ! $this->activeUndo();
    }

    /** Läuft oder wartet schon ein Rückgängig für diese Aktion? */
    public function activeUndo(): ?self
    {
        return self::where('undo_of', $this->id)->whereIn('status', ['pending', 'running'])->first();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => $this->isPlanned() ? 'geplant' : 'wartet',
            'running' => 'läuft',
            'done' => 'erledigt',
            'failed' => 'mit Fehlern',
            'undone' => 'rückgängig gemacht',
            'partly_undone' => 'teilweise rückgängig',
            default => $this->status,
        };
    }
}
