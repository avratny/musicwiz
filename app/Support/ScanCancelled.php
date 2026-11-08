<?php

namespace App\Support;

use RuntimeException;

/** Intern: Abbruch beim Durchgehen der Ordner (siehe Scanner::requestCancel). */
class ScanCancelled extends RuntimeException {}
