<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Zu breiter Genre-Wert („Rock/Pop“): wird entfernt statt zugeordnet. */
class GenreIgnore extends Model
{
    protected $guarded = [];
}
