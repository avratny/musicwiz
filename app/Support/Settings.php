<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Einstellungen aus SQLite, Startwerte aus config/musicwiz.php (.env).
 */
class Settings
{
    public const KEYS = ['base', 'inbox_paths', 'trash', 'artist_images', 'categories', 'no_album', 'non_letter_folder',
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

    /** @return list<array{name:string, folder:string, pattern:string, compilation:bool}> */
    public function categories(): array
    {
        return array_values(array_map(fn ($c) => [
            'name' => (string) $c['name'],
            'folder' => trim((string) $c['folder'], '/'),
            'pattern' => (string) $c['pattern'],
            'compilation' => (bool) ($c['compilation'] ?? false),
        ], $this->get('categories', [])));
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

    /** @return list<string> */
    public function inboxPaths(): array
    {
        return array_values(array_filter(array_map(fn ($p) => rtrim(trim($p), '/'), (array) $this->get('inbox_paths', []))));
    }

    public function isInInbox(string $path): bool
    {
        foreach ($this->inboxPaths() as $p) {
            if (str_starts_with($path, $p.'/')) {
                return true;
            }
        }

        return false;
    }
}
