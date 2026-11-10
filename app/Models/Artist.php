<?php

namespace App\Models;

use App\Support\ArtistNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends Model
{
    protected $guarded = [];

    protected $casts = ['name_confirmed_at' => 'datetime', 'default_genres' => 'array'];

    /** Höchstens so viele Standard-Genres (wie beim Album im Editor) */
    public const MAX_GENRES = 2;

    /** Standard-Genres (bis zu zwei, Reihenfolge = Wichtigkeit) */
    public function genres(): array
    {
        return array_values(array_filter((array) ($this->default_genres ?: ($this->default_genre ? [$this->default_genre] : []))));
    }

    /** Standard-Genres setzen; default_genre bleibt das erste (für alles, was nur eins liest). */
    public function setGenres(array $genres): static
    {
        $genres = array_slice(array_values(array_unique(array_filter(array_map('trim', $genres)))), 0, self::MAX_GENRES);
        $this->default_genres = $genres ?: null;
        $this->default_genre = $genres[0] ?? null;

        return $this;
    }

    protected static function booted(): void
    {
        static::saved(fn () => ArtistNames::flushProtected());
        static::deleted(fn () => ArtistNames::flushProtected());
        static::saving(fn (Artist $a) => $a->compare_key = ArtistNames::compareKey($a->name));
    }

    /** Name, wie er im Anzeigetext (ARTIST) steht – bei gleichnamigen Künstlern ohne Zusatz. */
    public function baseName(): string
    {
        return $this->base_name ?: $this->name;
    }

    public static function fullName(string $base, ?string $suffix): string
    {
        $suffix = trim((string) $suffix, ' []');

        return $suffix === '' ? trim($base) : trim($base).' ['.$suffix.']';
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ArtistAlias::class)->orderBy('alias');
    }

    /** Gelöschte Schreibweisen (MusicBrainz trägt sie nicht wieder ein) */
    public function rejectedAliases(): HasMany
    {
        return $this->hasMany(RejectedAlias::class)->orderBy('alias');
    }

    public function imageUrl(): ?string
    {
        return $this->image ? route('cover.artist', ['artist' => $this->id, 'v' => $this->updated_at?->timestamp]) : null;
    }
}
