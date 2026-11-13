<?php

namespace App\Models;

use App\Support\ArtistCredits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Artist extends Model
{
    protected $guarded = [];

    /** Zerlegen von Kombis hängt an bestätigten Namen, Schreibweisen und Entscheidungen */
    protected static function booted(): void
    {
        static::saved(fn () => app(ArtistCredits::class)->forget());
        static::deleted(fn () => app(ArtistCredits::class)->forget());
    }

    protected $casts = ['confirmed' => 'boolean', 'looked_up_at' => 'datetime'];

    public function aliases(): HasMany
    {
        return $this->hasMany(ArtistAlias::class)->orderBy('alias');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ArtistSource::class);
    }

    /** Name und alle Schreibweisen */
    public function spellings(): array
    {
        return [$this->name, ...$this->aliases->pluck('alias')->all()];
    }

    /** Lieder, mit denen der Künstler verbunden ist (Interpret oder Album-Interpret, siehe track_artist) */
    public function tracks(?string $role = null): Builder
    {
        return Track::query()->whereIn('id', DB::table('track_artist')->select('track_id')->where('artist_id', $this->id)
            ->when($role, fn ($q) => $q->where('role', $role)));
    }

    /** Pfad der Bilddatei oder null */
    public function imagePath(): ?string
    {
        $path = $this->image ? rtrim((string) config('musicwiz.artist_images'), '/').'/'.$this->image : null;

        return $path && is_file($path) ? $path : null;
    }
}
