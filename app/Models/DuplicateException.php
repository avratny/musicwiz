<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateException extends Model
{
    protected $guarded = [];

    protected $casts = ['members' => 'array'];
}
