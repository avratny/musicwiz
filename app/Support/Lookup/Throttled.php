<?php

namespace App\Support\Lookup;

use RuntimeException;

/** Ein Dienst hat uns vorübergehend gesperrt (zu viele Abfragen). */
class Throttled extends RuntimeException
{
    public function __construct(public string $service, public int $until)
    {
        parent::__construct($service.' bremst – wieder ab '.date('H:i', $until).' Uhr');
    }
}
