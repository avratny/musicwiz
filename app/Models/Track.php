<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Track extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'has_cover' => 'boolean',
        'compilation' => 'boolean',
        'rating' => 'integer',
        'loudness' => 'float',
        'loudness_peak' => 'float',
        'duration' => 'float',
        'scanned_at' => 'datetime',
    ];

    /** Grundfelder, die sich bearbeiten lassen (Tabelle und Flyout) – Beschriftung */
    public const EDITABLE = [
        'title' => 'Titel',
        'artist' => 'Interpret',
        'artists' => 'Interpreten einzeln',
        'album' => 'Album',
        'albumartist' => 'Album-Interpret',
        'albumartists' => 'Album-Interpreten einzeln',
        'artistsort' => 'Interpret (Sortierung)',
        'albumartistsort' => 'Album-Interpret (Sortierung)',
        'track' => 'Nr.',
        'track_total' => 'von',
        'disc' => 'CD',
        'disc_total' => 'von',
        'year' => 'Jahr',
        'releasedate' => 'Veröffentlicht',
        'mb_albumid' => 'MusicBrainz-Album-ID',
        'genre' => 'Genre',
        'comment' => 'Kommentar',
        'compilation' => 'Sampler',
        'rg_track_gain' => 'ReplayGain Lied',
        'rg_track_peak' => 'ReplayGain Lied-Peak',
        'rg_album_gain' => 'ReplayGain Album',
        'rg_album_peak' => 'ReplayGain Album-Peak',
        'rating' => 'Bewertung',      // 1–5 Sterne, null = keine (Ratings)
        'category' => 'Kategorie',   // LIB, LIB COMPILATION, BLACKHOLE, BLACKHOLE COMPILATION (Categories)
        'cover' => 'Cover',   // neu gesetztes Cover: Verweis in den Cover-Speicher (CoverStore)
        'fingerprint' => 'Akustischer Fingerabdruck',   // Chromaprint, Tag ACOUSTID_FINGERPRINT (Fingerprints)
    ];

    public const INTEGER_FIELDS = ['track', 'track_total', 'disc', 'disc_total'];

    public function changes(): HasMany
    {
        return $this->hasMany(TagChange::class)->latest('id');
    }

    public function durationLabel(): string
    {
        $s = (int) round($this->duration ?? 0);

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /** z. B. „FLAC 16/44,1“ bzw. „MP3 320“ */
    public function qualityLabel(): string
    {
        $format = $this->format ?: strtoupper($this->ext);
        if ($this->ext === 'flac') {
            return trim($format.' '.($this->bits ? $this->bits.'/' : '').($this->sample_rate ? rtrim(rtrim(number_format($this->sample_rate / 1000, 1, ',', ''), '0'), ',') : ''));
        }

        return trim($format.' '.($this->bitrate ? round($this->bitrate / 1000) : ''));
    }
}
