<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Support\Chat\ChatAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Eine Runde des Assistenten im Hintergrund (kann mehrere Modell- und Werkzeug-Aufrufe dauern). */
class ChatTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $conversationId)
    {
        $this->onQueue('research');
    }

    public function handle(ChatAgent $agent): void
    {
        $c = ChatConversation::find($this->conversationId);
        if ($c && $c->status === 'running') {
            $agent->run($c);
        }
    }
}
