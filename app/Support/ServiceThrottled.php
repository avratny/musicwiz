<?php

namespace App\Support;

use RuntimeException;

/** Ein Dienst bremst gerade (HTTP 429) – kein „nicht gefunden“, später erneut fragen. */
class ServiceThrottled extends RuntimeException {}
