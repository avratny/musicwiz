<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Einstellungen aus SQLite, Startwerte aus config/musicwiz.php (.env).
 */
class Settings
{
    public const KEYS = ['base', 'inbox', 'trash', 'artist_images', 'categories', 'no_album', 'non_letter_folder',
        'compilation_artist', 'replace_colon', 'replace_slash', 'id3_version', 'scan_throttle_ms', 'small_cover_px',
        'discogs_token', 'fanart_key', 'auto_cleanup',
        'yt_sleep_min', 'yt_sleep_max', 'yt_sleep_requests', 'yt_rate_mb', 'yt_daily_limit'];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = Schema::hasTable('settings') ? Setting::pluck('value', 'key')->all() : [];
            $this->cache = [];
            foreach (self::KEYS as $key) {
                $this->cache[$key] = array_key_exists($key, $stored) && $stored[$key] !== null ? $stored[$key] : config('musicwiz.'.$key);
            }
            // früher mehrere Inbox-Ordner: der erste gilt
            if (! isset($stored['inbox']) && ! empty($stored['inbox_paths'])) {
                $this->cache['inbox'] = (string) ((array) $stored['inbox_paths'])[0];
            }
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
        $this->cache = null;
    }

    /** Kopie mit ungespeicherten Werten (für die Live-Vorschau). */
    public function override(array $values): static
    {
        $copy = clone $this;
        $copy->cache = array_merge($this->all(), $values);

        return $copy;
    }

    public function reset(string $key): void
    {
        Setting::where('key', $key)->delete();
        $this->cache = null;
    }

    public function base(): string
    {
        return rtrim($this->get('base'), '/');
    }

    /**
     * Feste Kategorien: je Bereich (Lib, Blackhole) ein Ordner für Künstler und einer für Sampler.
     * Einstellbar sind nur Name (Kommentar-Tag), Zielordner und Muster.
     *
     * @return list<array{role:string, area:string, name:string, folder:string, pattern:string, compilation:bool}>
     */
    public function categories(): array
    {
        $stored = (array) $this->get('categories', []);
        $defaults = config('musicwiz.categories');
        $out = [];
        foreach (self::ROLES as $role => [$area, $compilation]) {
            // ältere Einstellungen: freie Liste statt fester Rollen – über den Namen zuordnen
            $c = $stored[$role]
                ?? collect($stored)->first(fn ($c) => is_array($c) && ($c['role'] ?? null) === $role)
                ?? collect($stored)->first(fn ($c) => is_array($c) && strcasecmp((string) ($c['name'] ?? ''), $defaults[$role]['name']) === 0)
                ?? [];
            $c = array_merge($defaults[$role], array_filter((array) $c, fn ($v) => is_string($v) && trim($v) !== ''));
            $out[] = [
                'role' => $role,
                'area' => $area,
                'name' => trim((string) $c['name']),
                'folder' => trim((string) $c['folder'], '/ '),
                'pattern' => trim((string) $c['pattern']),
                'compilation' => $compilation,
            ];
        }

        return $out;
    }

    /** Rolle => [Bereich, Compilation] */
    public const ROLES = [
        'lib' => ['library', false],
        'lib_compilation' => ['library', true],
        'blackhole' => ['blackhole', false],
        'blackhole_compilation' => ['blackhole', true],
    ];

    /** @return list<array{role:string, area:string, name:string, folder:string, pattern:string, compilation:bool}> */
    public function categoriesIn(string $area): array
    {
        return array_values(array_filter($this->categories(), fn ($c) => $c['area'] === $area));
    }

    public function category(?string $name): ?array
    {
        foreach ($this->categories() as $c) {
            if ($name !== null && strcasecmp($c['name'], trim($name)) === 0) {
                return $c;
            }
        }

        return null;
    }

    public function categoryRoot(array $category): string
    {
        return $this->base().'/'.$category['folder'];
    }

    /** Kategorie, in deren Zielordner der Pfad liegt. */
    public function categoryForPath(string $path): ?array
    {
        $best = null;
        foreach ($this->categories() as $c) {
            $root = $this->categoryRoot($c).'/';
            if (str_starts_with($path, $root) && ($best === null || strlen($root) > strlen($this->categoryRoot($best)))) {
                $best = $c;
            }
        }

        return $best;
    }

    public function inbox(): string
    {
        return rtrim(trim((string) $this->get('inbox')), '/');
    }

    public function isInInbox(string $path): bool
    {
        return $this->inbox() !== '' && str_starts_with($path, $this->inbox().'/');
    }

    /** Bereich eines Pfads im Index: inbox, blackhole oder library. */
    public function locationOf(string $path): string
    {
        if ($this->isInInbox($path)) {
            return 'inbox';
        }

        return ($this->categoryForPath($path)['area'] ?? null) === 'blackhole' ? 'blackhole' : 'library';
    }
}
