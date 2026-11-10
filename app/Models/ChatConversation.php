<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Unterhaltung mit dem Assistenten (siehe App\Support\Chat\ChatAgent). */
class ChatConversation extends Model
{
    protected $guarded = [];

    protected $casts = ['messages' => 'array', 'items' => 'array', 'pending' => 'array', 'usage' => 'array'];

    /** Anzeige-Eintrag anhängen und speichern */
    public function addItem(array $item): void
    {
        $items = $this->items ?? [];
        $items[] = $item + ['at' => now()->toIso8601String()];
        $this->items = $items;
        $this->save();
    }

    public function isBusy(): bool
    {
        return $this->status === 'running';
    }
}
