<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\TextRepair;
use RuntimeException;

/**
 * Claude schlägt für einen Wert mit verlorenen Zeichen die wahrscheinlichsten Schreibweisen vor (bis zu 10, mit kurzer
 * Begründung) – mit dem Anthropic-API-Key aus den Einstellungen. Die Vorschläge werden in text_repairs gespeichert.
 */
class TextRepairAi
{
    public const MODEL = 'claude-opus-5-5';

    public const MAX = 10;

    private const SYSTEM = <<<'TXT'
        Du reparierst Musik-Tags (Titel, Interpret, Album, Genre), deren Zeichen beim Speichern oder Umkodieren beschädigt wurden:
        verlorene Zeichen („�“ oder „?“ statt eines Buchstabens oder Sonderzeichens), falsch dekodierte Umlaute („Ã¶“ statt „ö“),
        unsichtbare Zeichen.

        Schlage für den beschädigten Wert die wahrscheinlichsten richtigen Schreibweisen vor – höchstens 10, die wahrscheinlichste
        zuerst. Ändere nur die beschädigten Stellen; alles andere (Wörter, Groß-/Kleinschreibung, Zahlen, Satzzeichen) bleibt
        genau wie im Original. Nutze dein Wissen über Interpreten, Alben, Sampler-Reihen und Titel und den Zusammenhang (andere
        Tags, Ordner, Dateiname). Begründe jeden Vorschlag in einem kurzen deutschen Halbsatz. Wenn du dir nur bei wenigen
        Möglichkeiten sicher bist, gib nur diese an.
        TXT;

    private const FIELD_LABELS = ['title' => 'Titel', 'artist' => 'Interpret', 'artists' => 'Interpreten einzeln', 'album' => 'Album',
        'albumartist' => 'Album-Interpret', 'albumartists' => 'Album-Interpreten einzeln', 'genre' => 'Genre'];

    public function __construct(private Settings $settings) {}

    public function available(): bool
    {
        return trim((string) $this->settings->get('anthropic_api_key')) !== '';
    }

    /**
     * Vorschläge holen und speichern (Fehler werden ebenfalls gespeichert und als RuntimeException weitergegeben).
     *
     * @param  array<string, int>  $fields  Feld => Zahl der Lieder
     * @param  list<array<string, string>>  $context  Lieder mit diesem Wert (TextRepairs::context)
     */
    public function suggest(string $value, array $fields, array $context): TextRepair
    {
        $repair = TextRepair::firstOrNew(['hash' => sha1($value)], ['value' => $value]);
        try {
            [$suggestions, $model] = $this->ask($value, $fields, $context);
            $repair->fill(['suggestions' => $suggestions, 'model' => $model, 'error' => null, 'asked_at' => now()])->save();
        } catch (RuntimeException $e) {
            $repair->fill(['error' => mb_substr($e->getMessage(), 0, 512), 'asked_at' => now()])->save();
            throw $e;
        }

        return $repair;
    }

    /** @return array{0: list<array{text: string, reason: string}>, 1: string} Vorschläge und tatsächlich verwendetes Modell */
    protected function ask(string $value, array $fields, array $context): array
    {
        $key = trim((string) $this->settings->get('anthropic_api_key'));
        if ($key === '') {
            throw new RuntimeException('Kein Anthropic-API-Key in den Einstellungen (Zugangsdaten).');
        }
        $where = collect($fields)->map(fn ($n, $f) => (self::FIELD_LABELS[$f] ?? $f).' ('.$n.' '.($n === 1 ? 'Lied' : 'Lieder').')')->implode(', ');
        $lines = collect($context)->map(fn ($t) => '- '.collect($t)->map(fn ($v, $k) => $k.': '.$v)->implode(' · '))->implode("\n");
        $prompt = "Feld: {$where}\nBeschädigter Wert: «{$value}»\n\nZusammenhang (Lieder mit diesem Wert):\n{$lines}";

        try {
            $message = (new Client(apiKey: $key))->beta->messages->create(
                model: self::MODEL,
                maxTokens: 16000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'suggestions' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => ['text' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                                        'required' => ['text', 'reason'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['suggestions'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                // lehnt das Modell aus Sicherheitsgründen ab, übernimmt serverseitig das vorgesehene Ausweichmodell
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIStatusException $e) {
            throw new RuntimeException('Claude antwortet mit Fehler '.$e->status.': '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException('Claude nicht erreichbar: '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude hat die Anfrage abgelehnt.');
        }
        $data = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                break;
            }
        }
        if (! is_array($data['suggestions'] ?? null)) {
            throw new RuntimeException('Claude hat keine lesbaren Vorschläge geliefert.');
        }

        return [self::clean($value, $data['suggestions']), (string) $message->model];
    }

    /** leere, doppelte und unveränderte Vorschläge weg, höchstens MAX */
    public static function clean(string $value, array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $s) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($s['text'] ?? '')));
            if ($text === '' || $text === $value || isset($out[$text])) {
                continue;
            }
            $out[$text] = ['text' => $text, 'reason' => trim((string) ($s['reason'] ?? ''))];
        }

        return array_slice(array_values($out), 0, self::MAX);
    }
}
