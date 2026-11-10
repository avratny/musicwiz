<?php

namespace App\Support\Chat;

use App\Models\ChatConversation;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

/**
 * Assistent im Chat-Overlay: Claude mit den Werkzeugen der Schnittstelle. Eine Runde läuft, bis Claude fertig ist – oder
 * bis eine Änderung die Freigabe des Nutzers braucht; dann wartet die Unterhaltung, bis er „Ausführen“ bzw. „Ablehnen“
 * klickt, und läuft danach mit dem Ergebnis weiter. In die Dateien schreibt der Assistent nie.
 */
class ChatAgent
{
    /** wählbare Modelle mit Preisen je Million Tokens (Eingabe, Ausgabe, aus dem Zwischenspeicher) */
    public const MODELS = [
        'claude-opus-5-5' => ['label' => 'Claude Opus 5.5 (Standard)', 'in' => 4.0, 'out' => 20.0, 'cache' => 0.20, 'fallback' => true],
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 (günstiger)', 'in' => 2.0, 'out' => 10.0, 'cache' => 0.20, 'fallback' => true],
        'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5 (am günstigsten)', 'in' => 0.10, 'out' => 0.50, 'cache' => 0.01, 'fallback' => false],
        'claude-fable-5-1' => ['label' => 'Claude Fable 5.1 (am stärksten, teuer)', 'in' => 10.0, 'out' => 50.0, 'cache' => 0.25, 'fallback' => true],
    ];

    /** höchstens so viele Modell-Aufrufe je Runde (Schutz gegen Endlosschleifen) */
    private const MAX_STEPS = 25;

    public function __construct(private ChatModel $model, private ChatTools $tools, private Settings $settings) {}

    public function modelId(): string
    {
        $m = (string) $this->settings->get('chat_model');

        return isset(self::MODELS[$m]) ? $m : 'claude-opus-5-5';
    }

    public function available(): bool
    {
        return trim((string) $this->settings->get('anthropic_api_key')) !== '';
    }

    /** Nachricht des Nutzers anhängen (mit der Seite, auf der er gerade ist). */
    public function addUserMessage(ChatConversation $c, string $text, ?string $page = null): void
    {
        $messages = $c->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => ($page ? '[Aktuelle Seite: '.$page."]\n\n" : '').$text];
        $c->messages = $messages;
        $c->title ??= mb_substr($text, 0, 80);
        $c->status = 'running';
        $c->addItem(['type' => 'user', 'text' => $text]);
    }

    /** Eine Runde: Claude antwortet und ruft Werkzeuge, bis fertig oder eine Freigabe nötig ist. */
    public function run(ChatConversation $c): void
    {
        try {
            for ($step = 0; $step < self::MAX_STEPS; $step++) {
                $response = $this->model->create($this->request($c));
                $this->account($c, $response);
                $content = $response['content'] ?? [];
                $messages = $c->messages;
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $c->messages = $messages;

                $text = trim(implode("\n\n", array_map(fn ($b) => $b['text'], array_filter($content, fn ($b) => ($b['type'] ?? '') === 'text'))));
                if ($text !== '') {
                    $c->addItem(['type' => 'assistant', 'text' => $text, 'cost' => $this->cost($response['usage'] ?? [], $response['model'] ?? $this->modelId())]);
                } else {
                    $c->save();
                }

                $stop = $response['stop_reason'] ?? 'end_turn';
                if ($stop === 'refusal') {
                    $c->addItem(['type' => 'error', 'text' => 'Claude hat diese Anfrage abgelehnt'.(! empty($response['stop_details']['category']) ? ' ('.$response['stop_details']['category'].')' : '').'.']);
                    break;
                }
                if ($stop === 'pause_turn') {
                    continue; // Claude macht weiter (gleicher Verlauf)
                }
                if ($stop !== 'tool_use') {
                    if ($stop === 'max_tokens') {
                        $c->addItem(['type' => 'error', 'text' => 'Antwort wurde abgeschnitten (zu lang).']);
                    }
                    break;
                }
                if ($this->handleTools($c, $content)) {
                    return; // wartet auf Freigabe
                }
            }
            $c->status = 'idle';
            $c->save();
        } catch (\Throwable $e) {
            report($e);
            $c->status = 'failed';
            $c->addItem(['type' => 'error', 'text' => 'Fehler: '.$e->getMessage()]);
        }
    }

    /**
     * Werkzeug-Aufrufe einer Antwort: lesende sofort, ändernde als Vorschlag zur Freigabe.
     *
     * @return bool true = wartet auf Freigabe
     */
    private function handleTools(ChatConversation $c, array $content): bool
    {
        $results = [];
        $proposals = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $name = (string) $block['name'];
            $input = (array) ($block['input'] ?? []);
            if (ChatTools::mutating($name)) {
                $proposals[] = ['id' => $block['id'], 'name' => $name, 'input' => $input];
                $c->addItem(['type' => 'proposal', 'id' => $block['id'], 'name' => $name, 'label' => ChatTools::label($name), 'input' => $input, 'state' => 'open']);

                continue;
            }
            $c->addItem(['type' => 'tool', 'name' => $name, 'label' => ChatTools::label($name), 'input' => $input]);
            $r = $this->tools->run($name, $input);
            $results[$block['id']] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $r['text']] + ($r['ok'] ? [] : ['is_error' => true]);
        }
        if ($proposals) {
            $c->pending = ['results' => $results, 'order' => array_column(array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_use'), 'id'), 'proposals' => $proposals];
            $c->status = 'awaiting';
            $c->save();

            return true;
        }
        $this->appendResults($c, $results, array_keys($results));

        return false;
    }

    /**
     * Freigabe: ausführen bzw. ablehnen. Sind alle Vorschläge entschieden, geht die Runde weiter.
     *
     * @return bool true = alle entschieden (Runde kann weiterlaufen)
     */
    public function decide(ChatConversation $c, string $toolUseId, bool $approve): bool
    {
        $pending = $c->pending;
        $proposal = collect($pending['proposals'] ?? [])->firstWhere('id', $toolUseId);
        if (! $proposal || isset($pending['results'][$toolUseId])) {
            return false;
        }
        if ($approve) {
            $r = $this->tools->run($proposal['name'], $proposal['input']);
            Log::info('Assistent: '.ChatTools::label($proposal['name']).' freigegeben', ['input' => $proposal['input'], 'ok' => $r['ok']]);
            $pending['results'][$toolUseId] = ['type' => 'tool_result', 'tool_use_id' => $toolUseId, 'content' => $r['text']] + ($r['ok'] ? [] : ['is_error' => true]);
            $state = $r['ok'] ? 'done' : 'failed';
        } else {
            $pending['results'][$toolUseId] = ['type' => 'tool_result', 'tool_use_id' => $toolUseId, 'content' => 'Vom Nutzer abgelehnt – nicht ausgeführt.', 'is_error' => true];
            $state = 'rejected';
        }
        $items = $c->items;
        foreach ($items as &$item) {
            if (($item['type'] ?? '') === 'proposal' && $item['id'] === $toolUseId) {
                $item['state'] = $state;
                $item['result'] = $state === 'failed' ? mb_substr($pending['results'][$toolUseId]['content'], 0, 300) : null;
            }
        }
        unset($item);
        $c->items = $items;
        $c->pending = $pending;
        $c->save();

        if (count($pending['results']) < count($pending['order'])) {
            return false;
        }
        $this->appendResults($c, $pending['results'], $pending['order']);
        $c->pending = null;
        $c->status = 'running';
        $c->save();

        return true;
    }

    /** Alle Werkzeug-Ergebnisse einer Antwort in einer Nutzer-Nachricht (Reihenfolge wie die Aufrufe). */
    private function appendResults(ChatConversation $c, array $results, array $order): void
    {
        $messages = $c->messages;
        $messages[] = ['role' => 'user', 'content' => array_values(array_map(fn ($id) => $results[$id], $order))];
        $c->messages = $messages;
        $c->save();
    }

    private function request(ChatConversation $c): array
    {
        $model = $this->modelId();

        return [
            'model' => $model,
            'maxTokens' => 16000,
            // stabiler Anfang (Werkzeuge + Anleitung) im Zwischenspeicher; der Verlauf über den automatischen Marker
            'system' => [['type' => 'text', 'text' => ChatPrompt::system($this->settings), 'cacheControl' => ['type' => 'ephemeral']]],
            'cacheControl' => ['type' => 'ephemeral'],
            'tools' => ChatTools::definitions(),
            'messages' => $c->messages,
            'outputConfig' => ['effort' => 'medium'],
            // bei Ablehnung aus Sicherheitsgründen serverseitig auf ein passendes Modell ausweichen (wo das Modell es kann)
            'fallbacks' => self::MODELS[$model]['fallback'] ? 'default' : null,
            'betas' => self::MODELS[$model]['fallback'] ? ['server-side-fallback-2026-07-01'] : null,
        ];
    }

    /** Verbrauch und geschätzte Kosten aufsummieren */
    private function account(ChatConversation $c, array $response): void
    {
        $u = $response['usage'] ?? [];
        $sum = $c->usage ?? ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cost' => 0.0];
        $sum['input'] += (int) ($u['input_tokens'] ?? 0);
        $sum['output'] += (int) ($u['output_tokens'] ?? 0);
        $sum['cache_read'] += (int) ($u['cache_read_input_tokens'] ?? 0);
        $sum['cache_write'] += (int) ($u['cache_creation_input_tokens'] ?? 0);
        $sum['cost'] = round($sum['cost'] + $this->cost($u, $response['model'] ?? $this->modelId()), 5);
        $c->usage = $sum;
        $c->model = $response['model'] ?? $this->modelId();
    }

    /** geschätzte Kosten in US-Dollar (Schreiben in den Zwischenspeicher: 1,25 × Eingabepreis) */
    public function cost(array $usage, string $model): float
    {
        $p = self::MODELS[$model] ?? self::MODELS['claude-opus-5-5'];

        return ((int) ($usage['input_tokens'] ?? 0) * $p['in'] + (int) ($usage['output_tokens'] ?? 0) * $p['out']
            + (int) ($usage['cache_read_input_tokens'] ?? 0) * $p['cache'] + (int) ($usage['cache_creation_input_tokens'] ?? 0) * $p['in'] * 1.25) / 1_000_000;
    }
}
