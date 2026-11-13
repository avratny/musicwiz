<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ein Hintergrundvorgang (Leiste über dem Player). */
class ActivityRecord extends Model
{
    protected $table = 'activities';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['running' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
