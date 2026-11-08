<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Track extends Model
{
    protected $guarded = [];

    protected $casts = [
        'artists' => 'array',
        'albumartists' => 'array',
        'genres' => 'array',
        'compilation' => 'boolean',
        'has_lyrics' => 'boolean',
        'cover_embedded' => 'boolean',
        'writable' => 'boolean',
        'mtime' => 'integer',
        'size' => 'integer',
    ];

    public const TAG_KEYS = ['title', 'artist', 'artists', 'album', 'albumartist', 'albumartists', 'track', 'tracktotal',
        'disc', 'disctotal', 'year', 'genres', 'comment', 'compilation'];

    /** Relation heißt nicht "album", weil die Spalte "album" den Albumnamen enthält. */
    public function albumRecord(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'album_id');
    }

    /** Tags im Format des Tag-Tools. */
    public function tags(): array
    {
        $out = [];
        foreach (self::TAG_KEYS as $k) {
            $out[$k] = $this->{$k} ?? (in_array($k, ['artists', 'albumartists', 'genres']) ? [] : ($k === 'compilation' ? false : ''));
        }

        return $out;
    }

    /** Aus einem YouTube-Download (Tag SOURCE, bleibt beim Einordnen erhalten). */
    public function isFromYouTube(): bool
    {
        return strcasecmp((string) $this->source, 'YouTube') === 0;
    }

    public function durationLabel(): string
    {
        $s = (int) round($this->duration ?? 0);

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
