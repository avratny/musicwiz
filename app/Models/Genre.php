<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Genre extends Model
{
    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Genre::class, 'parent_id')->orderBy('position')->orderBy('name');
    }
}
