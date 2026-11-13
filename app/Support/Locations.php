<?php

namespace App\Support;

use App\Models\Track;

/** Die vier Bereiche: Ordner, Namen, Anzahl Lieder. */
class Locations
{
    public const LABELS = ['inbox' => 'Inbox', 'library' => 'Lib', 'blackhole' => 'Blackhole', 'trash' => 'Papierkorb'];

    /**
     * Bereiche, die ignoriert werden: keine Verbindung zum Künstlerverzeichnis (also kein Abgleich), keine Änderungen an
     * den Tags (weder System noch Hand), keine Kategorie. Von dort geht es nur zurück in die Inbox.
     */
    public const IGNORED = ['blackhole'];

    public static function ignored(?string $location): bool
    {
        return in_array($location, self::IGNORED, true);
    }

    /** Adresse der Seite je Bereich */
    public const SLUGS = ['inbox' => 'inbox', 'library' => 'lib', 'blackhole' => 'blackhole', 'trash' => 'papierkorb'];

    public static function root(string $location): string
    {
        return rtrim((string) config('musicwiz.locations.'.$location), '/');
    }

    /** Bereich, in dem dieser Pfad liegt (die genaueste Wurzel – der Papierkorb kann in der Lib liegen) */
    public static function of(string $path): ?string
    {
        $found = null;
        foreach (array_keys(self::LABELS) as $location) {
            $root = self::root($location);
            if ($root !== '' && str_starts_with($path, $root.'/') && ($found === null || strlen($root) > strlen(self::root($found)))) {
                $found = $location;
            }
        }

        return $found;
    }

    public static function fromSlug(string $slug): string
    {
        $location = array_search($slug, self::SLUGS, true);
        abort_if($location === false, 404);

        return $location;
    }

    /** @return array<string, int> Bereich => Anzahl Lieder */
    public static function counts(): array
    {
        $counts = Track::selectRaw('location, COUNT(*) AS n')->groupBy('location')->pluck('n', 'location');

        return array_map(fn ($l) => (int) ($counts[$l] ?? 0), array_combine(array_keys(self::LABELS), array_keys(self::LABELS)));
    }
}
