<?php

namespace Tests\Unit;

use App\Support\ComboVerdict;
use PHPUnit\Framework\TestCase;

class ComboVerdictTest extends TestCase
{
    private static function ok(?string $type = null, string $match = 'exakt'): array
    {
        return ['status' => 'ok', 'match' => $match, 'type' => $type];
    }

    private static function missing(): array
    {
        return ['status' => 'nicht_gefunden', 'match' => null, 'type' => null];
    }

    private static function combo(string $name, array $parts, array $known = []): array
    {
        return ['name' => $name, 'parts' => $parts, 'known' => $known];
    }

    public function test_musicbrainz_group_keeps_the_name_whole(): void
    {
        $v = ComboVerdict::for(self::combo('Bill Haley And His Comets', ['Bill Haley', 'His Comets']), [
            'Bill Haley And His Comets' => ['musicbrainz' => self::ok('Group', 'zeichen')],
            'His Comets' => ['musicbrainz' => ['status' => 'fehler', 'match' => null, 'type' => null]],
        ]);

        $this->assertSame(['whole', 'sicher'], [$v['action'], $v['confidence']], 'Teile egal, MusicBrainz-Fehler beim Teil auch');
    }

    public function test_a_part_nobody_knows_reliably_means_a_band_name(): void
    {
        $checks = [
            'Danny & the Juniors' => ['musicbrainz' => self::missing(), 'discogs' => self::missing(), 'audiodb' => self::ok()],
            'Danny' => ['musicbrainz' => self::missing(), 'spotify' => self::ok(), 'discogs' => self::missing(), 'audiodb' => self::missing()],
            'the Juniors' => ['musicbrainz' => self::missing(), 'discogs' => self::ok(), 'audiodb' => self::ok()],
        ];
        $v = ComboVerdict::for(self::combo('Danny & the Juniors', ['Danny', 'the Juniors']), $checks);

        $this->assertSame(['whole', 'sicher'], [$v['action'], $v['confidence']], 'Spotify allein ist nicht verlässlich');
        $this->assertStringContainsString('„Danny“ kennt kein Dienst', $v['reasons'][0]);

        // eigene Lieder machen den Teil bekannt
        $v = ComboVerdict::for(self::combo('Danny & the Juniors', ['Danny', 'the Juniors'], ['Danny' => ['alone' => 3, 'others' => 0]]), $checks);
        $this->assertSame('split', $v['action'], 'jetzt sind alle Teile bekannt, MusicBrainz und Discogs kennen das Ganze nicht');
    }

    public function test_an_own_discogs_or_musicbrainz_entry_for_the_whole_name_keeps_it_whole_like_beam_and_yanou(): void
    {
        $error = ['status' => 'fehler', 'match' => null, 'type' => null];
        $checks = [
            'Beam & Yanou' => ['musicbrainz' => $error, 'discogs' => self::ok(), 'audiodb' => self::missing()],
            'Beam' => ['musicbrainz' => self::missing(), 'discogs' => self::ok(), 'audiodb' => self::ok()],
            'Yanou' => ['musicbrainz' => self::ok('Person'), 'discogs' => self::ok()],
        ];
        $v = ComboVerdict::for(self::combo('Beam & Yanou', ['Beam', 'Yanou']), $checks);

        $this->assertSame(['whole', 'wahrscheinlich'], [$v['action'], $v['confidence']], 'beide Teile bekannt – trotzdem ein festes Projekt');
        $this->assertStringContainsString('Discogs führt', $v['reasons'][0]);

        $checks['Beam & Yanou']['musicbrainz'] = self::ok(null, 'zeichen');
        $this->assertSame(['whole', 'sicher'], array_values(array_slice(ComboVerdict::for(self::combo('Beam & Yanou', ['Beam', 'Yanou']), $checks), 0, 2)));
    }

    public function test_split_only_when_musicbrainz_and_discogs_answered_that_they_do_not_know_the_whole(): void
    {
        $checks = [
            'Calvin Harris & Dua Lipa' => ['musicbrainz' => self::missing(), 'discogs' => self::missing(), 'spotify' => self::ok(),
                'lastfm' => ['status' => 'ok', 'match' => 'exakt', 'type' => null, 'listeners' => 12000]],
            'Calvin Harris' => ['musicbrainz' => self::ok('Person'), 'lastfm' => ['status' => 'ok', 'match' => 'exakt', 'type' => null, 'listeners' => 4000000]],
            'Dua Lipa' => ['musicbrainz' => self::ok('Person')],
        ];
        $combo = self::combo('Calvin Harris & Dua Lipa', ['Calvin Harris', 'Dua Lipa']);
        $v = ComboVerdict::for($combo, $checks);
        $this->assertSame(['split', 'sicher'], [$v['action'], $v['confidence']], 'Spotify und Last.fm kennen fast alles – zählen nicht');
        $this->assertContains('Last.fm-Hörer: ganz 12.000 · Calvin Harris 4.000.000', $v['reasons']);

        $checks['Calvin Harris & Dua Lipa']['musicbrainz'] = ['status' => 'fehler', 'match' => null, 'type' => null];
        $this->assertNull(ComboVerdict::for($combo, $checks)['action'], 'ohne Antwort von MusicBrainz nie Trennen');
    }

    public function test_without_checks_only_own_tracks_count(): void
    {
        $combo = self::combo('Beam & Yanou', ['Beam', 'Yanou'], ['Beam' => ['alone' => 2, 'others' => 0], 'Yanou' => ['alone' => 1, 'others' => 0]]);
        $this->assertSame('split', ComboVerdict::for($combo, [])['action']);
        $this->assertNull(ComboVerdict::for(self::combo('A & B', ['A', 'B']), [])['action']);
    }

    public function test_ambiguous_parts_lower_the_confidence_of_a_split(): void
    {
        $checks = [
            'Beam & Yanou' => ['musicbrainz' => self::missing(), 'discogs' => self::missing()],
            'Beam' => ['musicbrainz' => self::ok('Person') + ['namesakes' => 5]],
            'Yanou' => ['musicbrainz' => self::ok('Person') + ['namesakes' => 1]],
        ];
        $v = ComboVerdict::for(self::combo('Beam & Yanou', ['Beam', 'Yanou']), $checks);

        $this->assertSame(['split', 'wahrscheinlich'], [$v['action'], $v['confidence']]);
        $this->assertContains('mehrdeutig: „Beam“ (5 heißen so)', $v['reasons']);

        // eigene Lieder: dann ist der Teil wirklich bekannt
        $v = ComboVerdict::for(self::combo('Beam & Yanou', ['Beam', 'Yanou'], ['Beam' => ['alone' => 2, 'others' => 0]]), $checks);
        $this->assertSame('sicher', $v['confidence']);
    }
}
