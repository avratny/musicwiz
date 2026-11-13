<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\Artist;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Interpreten › Schreibweise: Claude entscheidet, welche Schreibweise eines Interpreten stimmt – unsere (aus den Tags),
 * die von MusicBrainz oder eine dritte – mit kurzer Begründung. Gespeichert in artist_spelling_checks; die Antwort gilt,
 * solange beide Schreibweisen gleich bleiben.
 */
class SpellingAi
{
    public const MODEL = TextRepairAi::MODEL;

    private const SYSTEM = <<<'TXT'
        Du prüfst die Schreibweise von Interpreten-Namen in einer Musiksammlung. Gegeben sind die Schreibweise aus den Tags
        der Sammlung und die von MusicBrainz, dazu, wie andere Dienste den Namen schreiben, und Lieder des Interpreten.

        Entscheide, welche Schreibweise der Interpret offiziell verwendet (auf Veröffentlichungen, Website, bei Labels) –
        Groß-/Kleinschreibung, Leerzeichen, Bindestriche, Punkte, Akzente, Artikel. MusicBrainz ist meist richtig, aber nicht
        immer; die Tags sind oft nachlässig geschrieben. Ist keine der beiden richtig, nenne die richtige (source "other").
        Ändere dabei nur die Schreibweise, nie den Namen selbst (kein anderer Künstlername, kein bürgerlicher Name).
        Begründe kurz auf Deutsch (ein Satz).
        TXT;

    public function __construct(private Settings $settings) {}

    public function available(): bool
    {
        return trim((string) $this->settings->get('anthropic_api_key')) !== '';
    }

    /** Gespeicherte Antwort, wenn sie noch zu beiden Schreibweisen passt */
    public static function answer(Artist $artist, string $theirs): ?object
    {
        return DB::table('artist_spelling_checks')->where('artist_id', $artist->id)->where('ours', $artist->name)->where('theirs', $theirs)->first();
    }

    /**
     * Fragen und speichern (Fehler werden ebenfalls gespeichert und als RuntimeException weitergegeben).
     *
     * @param  array  $group  Treffergruppe (LookupGroups) – Schreibweisen der Dienste
     */
    public function check(Artist $artist, string $theirs, ?object $mb, array $group): object
    {
        $row = ['ours' => $artist->name, 'theirs' => $theirs, 'asked_at' => now()];
        try {
            [$answer, $model] = $this->ask($artist->name, $theirs, $this->context($artist, $mb, $group));
            $row += ['spelling' => $answer['spelling'], 'source' => $answer['source'], 'reason' => $answer['reason'], 'model' => $model, 'error' => null];
        } catch (RuntimeException $e) {
            $row += ['spelling' => null, 'source' => null, 'reason' => null, 'model' => null, 'error' => mb_substr($e->getMessage(), 0, 512)];
            DB::table('artist_spelling_checks')->updateOrInsert(['artist_id' => $artist->id], $row);
            throw $e;
        }
        DB::table('artist_spelling_checks')->updateOrInsert(['artist_id' => $artist->id], $row);

        return self::answer($artist, $theirs);
    }

    /** @return array{0: array{spelling: string, source: string, reason: string}, 1: string} Antwort und tatsächlich verwendetes Modell */
    protected function ask(string $ours, string $theirs, string $context): array
    {
        $key = trim((string) $this->settings->get('anthropic_api_key'));
        if ($key === '') {
            throw new RuntimeException('Kein Anthropic-API-Key in den Einstellungen (Zugangsdaten).');
        }
        $prompt = "Schreibweise in den Tags: «{$ours}»\nSchreibweise bei MusicBrainz: «{$theirs}»\n\n{$context}";
        try {
            $message = (new Client(apiKey: $key))->beta->messages->create(
                model: self::MODEL,
                maxTokens: 8000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'source' => ['type' => 'string', 'enum' => ['ours', 'musicbrainz', 'other']],
                                'spelling' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['source', 'spelling', 'reason'],
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
        if (! is_array($data) || ! isset($data['source'], $data['spelling'])) {
            throw new RuntimeException('Claude hat keine lesbare Antwort geliefert.');
        }

        return [self::clean($ours, $theirs, $data), (string) $message->model];
    }

    /**
     * Antwort vereinheitlichen: „ours“/„musicbrainz“ ergeben genau diese Schreibweise, leere „other“ gilt nicht. Eine
     * dritte Schreibweise muss derselbe Name sein (nur Groß/klein, Akzente, Satzzeichen anders) – sonst „unsure“: wird
     * angezeigt und lässt sich einzeln wählen, aber nie ungefragt übernehmen.
     */
    public static function clean(string $ours, string $theirs, array $data): array
    {
        $spelling = ArtistMatcher::plain((string) ($data['spelling'] ?? ''));
        $source = match (true) {
            $spelling === $ours || ($data['source'] ?? '') === 'ours' && $spelling === '' => 'ours',
            $spelling === $theirs || ($data['source'] ?? '') === 'musicbrainz' && $spelling === '' => 'musicbrainz',
            $spelling !== '' && in_array(ArtistMatcher::key($spelling), [ArtistMatcher::key($ours), ArtistMatcher::key($theirs)], true) => 'other',
            $spelling !== '' => 'unsure',
            default => throw new RuntimeException('Claude hat keine Schreibweise genannt.'),
        };

        return ['source' => $source, 'spelling' => ['ours' => $ours, 'musicbrainz' => $theirs][$source] ?? $spelling, 'reason' => trim((string) ($data['reason'] ?? ''))];
    }

    /** Zusammenhang: Eintrag bei MusicBrainz, Schreibweisen der anderen Dienste, einige Lieder */
    private function context(Artist $artist, ?object $mb, array $group): string
    {
        $lines = [];
        if ($mb) {
            $lines[] = 'MusicBrainz: '.SimilarVerdict::describe($mb);
        }
        foreach ($group['spellings'] ?? [] as $name => $services) {
            $lines[] = 'Schreibweise «'.$name.'» bei: '.implode(', ', $services);
        }
        $tracks = $artist->tracks()->orderBy('album')->orderBy('title')->limit(10)->get(['title', 'album', 'year']);
        if ($tracks->isNotEmpty()) {
            $lines[] = "\nLieder in der Sammlung:";
            foreach ($tracks as $t) {
                $lines[] = '- '.collect(['Titel' => $t->title, 'Album' => $t->album, 'Jahr' => $t->year])->filter()->map(fn ($v, $k) => $k.': '.$v)->implode(' · ');
            }
        }

        return implode("\n", $lines);
    }
}
