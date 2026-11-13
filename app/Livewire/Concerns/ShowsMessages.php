<?php

namespace App\Livewire\Concerns;

use InvalidArgumentException;

/** Meldung bzw. Fehler oben auf der Seite; attempt() fängt Eingabefehler (InvalidArgumentException) als Meldung ab. */
trait ShowsMessages
{
    public ?string $error = null;

    public ?string $message = null;

    /** $success gilt, wenn $action selbst keine Meldung gesetzt hat */
    protected function attempt(callable $action, string $success = ''): void
    {
        $this->error = $this->message = null;
        try {
            $action();
            $this->message ??= $success;
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function say(string $message): void
    {
        $this->error = null;
        $this->message = $message;
    }
}
