<?php

namespace App\Livewire;

use App\Jobs\ChatTurn;
use App\Models\ChatConversation;
use App\Support\Chat\ChatAgent;
use Livewire\Component;

/**
 * Chat-Overlay mit dem Assistenten – auf jeder Seite über den runden Knopf unten rechts. Die Runde läuft im Hintergrund,
 * das Overlay fragt regelmäßig nach; Änderungen erscheinen als Karte mit „Ausführen“ / „Ablehnen“.
 */
class ChatPanel extends Component
{
    public ?int $conversationId = null;

    public string $text = '';

    public function mount(): void
    {
        $this->conversationId = session('chat.conversation');
    }

    private function conversation(): ?ChatConversation
    {
        return $this->conversationId ? ChatConversation::find($this->conversationId) : null;
    }

    /** @param  string  $page  Titel und Pfad der Seite, auf der der Nutzer gerade ist */
    public function send(ChatAgent $agent, string $page = ''): void
    {
        $text = trim($this->text);
        $c = $this->conversation();
        if ($text === '' || ! $agent->available() || ($c && in_array($c->status, ['running', 'awaiting'], true))) {
            return;
        }
        $c ??= ChatConversation::create(['messages' => [], 'items' => []]);
        $agent->addUserMessage($c, $text, mb_substr(trim($page), 0, 200) ?: null);
        $this->text = '';
        $this->conversationId = $c->id;
        session(['chat.conversation' => $c->id]);
        ChatTurn::dispatch($c->id);
    }

    public function decide(ChatAgent $agent, string $toolUseId, bool $approve): void
    {
        $c = $this->conversation();
        if ($c && $c->status === 'awaiting' && $agent->decide($c, $toolUseId, $approve)) {
            ChatTurn::dispatch($c->id);
        }
        $this->dispatch('activity-changed');
    }

    /** Hängt eine Runde (Worker neu gestartet o. Ä.): wieder freigeben */
    public function reset_(): void
    {
        $c = $this->conversation();
        if ($c && $c->status !== 'awaiting') {
            $c->update(['status' => 'idle']);
        }
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->text = '';
        session()->forget('chat.conversation');
    }

    public function render(ChatAgent $agent)
    {
        $c = $this->conversation();

        return view('livewire.chat-panel', [
            'available' => $agent->available(),
            'conversation' => $c,
            'items' => $c?->items ?? [],
            'status' => $c?->status ?? 'idle',
            'cost' => (float) ($c?->usage['cost'] ?? 0),
            'modelLabel' => ChatAgent::MODELS[$agent->modelId()]['label'],
            'stale' => $c && $c->status === 'running' && $c->updated_at?->lt(now()->subMinutes(10)),
        ]);
    }

    /** „ca. 0,03 $“ – kleine Beträge lesbar */
    public static function money(float $usd): string
    {
        if ($usd <= 0) {
            return '0 $';
        }

        return $usd < 0.01 ? '< 0,01 $' : number_format($usd, 2, ',', '.').' $';
    }
}
