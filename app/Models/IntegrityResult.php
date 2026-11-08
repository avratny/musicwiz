<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrityResult extends Model
{
    protected $guarded = [];

    protected $casts = ['ok' => 'boolean', 'mtime' => 'integer', 'size' => 'integer'];
}
