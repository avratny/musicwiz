<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoryEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['old_tags' => 'array', 'new_tags' => 'array', 'cover_changed' => 'boolean', 'pending' => 'boolean', 'undone_at' => 'datetime'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
