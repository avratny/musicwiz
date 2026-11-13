<?php

namespace App\Support;

use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Tags bearbeiten – nur in der Datenbank. Jede tatsächliche Änderung wird in tag_changes festgehalten (Feld, alt, neu,
 * manuell oder vom System); in die Datei kommt sie erst später. Kommt ein Feld wieder auf den Stand vor der ersten
 * Änderung (also den der Datei), heben sich die Änderungen auf und werden entfernt.
 */
class TagEditor
{
    public const MANUAL = 'manual';

    public const SYSTEM = 'system';

    /**
     * @param  array<string, mixed>  $values  Feld => neuer Wert (nur Felder aus Track::EDITABLE)
     * @param  string|null  $reason  Grund (vor allem bei System-Änderungen, z. B. „Künstler umbenannt: …“)
     * @return array<string, mixed> tatsächlich geänderte Felder mit neuem Wert
     */
    public function update(Track $track, array $values, string $source = self::MANUAL, ?string $reason = null): array
    {
        // Blackhole wird ignoriert: dort ändert sich nichts
        if (Locations::ignored($track->location)) {
            return [];
        }
        // Interpret geändert: ARTISTS mitziehen, solange es die Zerlegung des bisherigen Werts ist (nicht von Hand gepflegt)
        $credits = app(ArtistCredits::class);
        foreach (ArtistCredits::FIELDS as $field => $list) {
            if (array_key_exists($field, $values) && ! array_key_exists($list, $values) && $credits->follows($track->getAttribute($list), $track->getAttribute($field))) {
                $values[$list] = $credits->follow($track->getAttribute($list), self::normalize($field, $values[$field]));
            }
        }
        // Interpret geändert: Sortiername zieht mit, solange er der eindeutige zum bisherigen Namen war (oder fehlte)
        $sorts = app(SortNames::class);
        foreach (SortNames::FIELDS as $sortField => $nameField) {
            if (array_key_exists($nameField, $values) && ! array_key_exists($sortField, $values)
                && in_array($track->getAttribute($sortField), [null, $sorts->expected($track->getAttribute($nameField), $track->getAttribute(ArtistCredits::FIELDS[$nameField]))], true)) {
                $list = ArtistCredits::FIELDS[$nameField];
                $values[$sortField] = $sorts->expected(self::normalize($nameField, $values[$nameField]), array_key_exists($list, $values) ? $values[$list] : $track->getAttribute($list));
            }
        }
        // Sampler ja/nein geändert (Flag oder Album-Interpret „Various Artists“): Kategorie zieht mit, im selben Bereich
        if ((array_key_exists('compilation', $values) || array_key_exists('albumartist', $values)) && ! array_key_exists('category', $values) && $track->category) {
            $compilation = self::normalize('compilation', $values['compilation'] ?? $track->compilation)
                || (array_key_exists('albumartist', $values) ? self::normalize('albumartist', $values['albumartist']) : $track->albumartist) === NameRules::VARIOUS;
            $values['category'] = Categories::follow($track->category, $compilation);
        }
        $changed = [];
        foreach (array_intersect_key($values, Track::EDITABLE) as $field => $value) {
            $new = self::normalize($field, $value);
            $old = $track->getAttribute($field);
            if ($field === 'compilation' ? (bool) $old === $new : (string) $old === (string) $new) {
                continue;
            }
            $changed[$field] = ['old' => $old, 'new' => $new];
        }
        if (! $changed) {
            return [];
        }
        DB::transaction(function () use ($track, $changed, $source, $reason) {
            foreach ($changed as $field => ['old' => $old, 'new' => $new]) {
                $track->setAttribute($field, $new);
                // zurück auf den Stand vor der ersten Änderung (= Datei): die Änderungen heben sich auf → alle entfernen
                $first = TagChange::where('track_id', $track->id)->where('field', $field)->orderBy('id')->first();
                if ($first && $first->old_value === self::text($new)) {
                    TagChange::where('track_id', $track->id)->where('field', $field)->delete();

                    continue;
                }
                TagChange::create(['track_id' => $track->id, 'field' => $field, 'old_value' => self::text($old), 'new_value' => self::text($new),
                    'source' => $source, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null]);
            }
            $track->save();
        });
        $this->changed($track, $changed);

        return array_map(fn ($c) => $c['new'], $changed);
    }

    /**
     * Felder eines Lieds auf den Stand der Datei zurücksetzen (alter Wert der ersten Änderung) – die Änderungen dieser
     * Felder werden entfernt, als wären sie nie gemacht worden.
     *
     * @param  list<string>  $fields
     * @return list<string> zurückgesetzte Felder
     */
    public function revert(Track $track, array $fields): array
    {
        $changed = [];
        DB::transaction(function () use ($track, $fields, &$changed) {
            foreach ($fields as $field) {
                $first = TagChange::where('track_id', $track->id)->where('field', $field)->orderBy('id')->first();
                if (! $first || ! isset(Track::EDITABLE[$field])) {
                    continue;
                }
                $old = $first->old_value;
                $value = match (true) {
                    $field === 'compilation' => $old === '1',
                    in_array($field, [...Track::INTEGER_FIELDS, 'rating'], true) => $old === null ? null : (int) $old,
                    default => $old,
                };
                $changed[$field] = ['old' => $track->getAttribute($field), 'new' => $value];
                $track->setAttribute($field, $value);
                TagChange::where('track_id', $track->id)->where('field', $field)->delete();
            }
            $track->save();
        });
        $this->changed($track, $changed);

        return array_keys($changed);
    }

    /** nach einer Änderung: Verbindungen zum Künstlerverzeichnis und Genres der Interpreten nachziehen */
    private function changed(Track $track, array $changed): void
    {
        if (! $changed) {
            return;
        }
        $credits = app(ArtistCredits::class);
        // Interpret bzw. Album-Interpret geändert: Verbindung zum Künstlerverzeichnis neu (unbekannter Name → neuer Künstler)
        if (array_intersect_key($changed, array_flip([...ArtistLinker::ROLES, ...ArtistCredits::FIELDS]))) {
            app(ArtistLinker::class)->link([$track]);
        }
        // Genre oder Interpret geändert: Genres der Interpreten dieses Lieds neu verknüpfen
        if (isset($changed['genre']) || isset($changed['artist']) || isset($changed['artists'])) {
            $artistIds = DB::table('track_artist')->where('track_id', $track->id)->where('role', 'artist')->pluck('artist_id')->all();
            // die bisherigen Interpreten (nur vorhandene – nichts anlegen)
            $old = isset($changed['artist']) ? array_column($credits->known($credits->credits((string) $changed['artist']['old'])), 'id') : [];
            app(ArtistGenres::class)->syncIds(array_values(array_unique([...$artistIds, ...$old])));
        }
    }

    public static function normalize(string $field, mixed $value): mixed
    {
        if ($field === 'compilation') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($field === 'category') {
            return Categories::normalize($value);
        }
        if ($field === 'rating') {
            return Ratings::clamp($value);
        }
        if ($field === 'lyrics') {
            return Lyrics::normalize($value);   // mehrzeilig – Zeilen bleiben
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if (in_array($field, TextCleanup::FIELDS, true)) {
            $value = TextCleanup::clean($value);
        }
        if (in_array($field, ArtistCredits::FIELDS, true)) {
            $value = implode(ArtistCredits::SEPARATOR, ArtistCredits::list($value));
        }
        if (in_array($field, Track::INTEGER_FIELDS, true)) {
            $n = (int) preg_replace('/\D.*$/', '', $value);

            return $n > 0 ? min($n, 65535) : null;
        }

        return $value === '' ? null : $value;
    }

    private static function text(mixed $v): ?string
    {
        return $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
    }
}
