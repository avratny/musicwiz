<?php

namespace App\Models;

use App\Support\Scanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    protected $guarded = [];

    protected $casts = [
        'genres' => 'array',
        'dirs' => 'array',
        'disc_map' => 'array',
        'is_compilation' => 'boolean',
        'cover_embedded' => 'boolean',
    ];

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderByRaw('CAST(disc AS INTEGER), CAST(track AS INTEGER), filename');
    }

    /** Alle Ordner des Albums (Mehrfach-CD in der Inbox: mehrere). */
    public function allDirs(): array
    {
        return $this->dirs ?: [$this->dir];
    }

    /** CD-Nummer eines Ordners im Set (von Hand zugeordnet bzw. erkannt). */
    public function discForDir(string $dir): ?int
    {
        return isset($this->disc_map[$dir]) ? (int) $this->disc_map[$dir] : null;
    }

    public function discCount(): int
    {
        return count($this->allDirs());
    }

    /** CD-Nummern der Ordner einer Mehrfach-CD (aus dem Ordnernamen, sonst aus den Tags). */
    public function discNumbers(): array
    {
        if ($this->discCount() < 2) {
            return [];
        }
        $numbers = [];
        foreach ($this->allDirs() as $i => $dir) {
            $n = $this->discForDir($dir)
                ?: Scanner::discFromName(basename($dir))
                ?? (int) $this->tracks()->where('dir', $dir)->selectRaw('MAX(CAST(disc AS INTEGER)) as d')->value('d');
            $numbers[] = $n ?: $i + 1;
        }
        sort($numbers);

        return array_values(array_unique($numbers));
    }

    /** Lücken, z. B. Disc 1, 2, 4 vorhanden -> [3]; berücksichtigt auch die Gesamtzahl im Tag (z. B. 2/3). */
    public function missingDiscs(): array
    {
        $have = $this->discNumbers();
        if (! $have) {
            return [];
        }
        $total = max(max($have), (int) $this->tracks()->selectRaw('MAX(CAST(disctotal AS INTEGER)) as t')->value('t'));

        return array_values(array_diff(range(1, $total), $have));
    }

    public function hasCover(): bool
    {
        return $this->cover_file !== null || $this->cover_embedded;
    }

    public function coverUrl(int $size = 400): ?string
    {
        return $this->hasCover() ? route('cover.album', ['album' => $this->id, 's' => $size, 'v' => $this->updated_at?->timestamp]) : null;
    }

    public function editUrl(): string
    {
        return match ($this->location) {
            'inbox' => route('inbox.album', $this),
            'blackhole' => route('blackhole', ['open' => $this->id]),
            default => route('library.album', $this),
        };
    }

    public function durationLabel(): string
    {
        $m = intdiv($this->duration, 60);

        return $m >= 60 ? sprintf('%d:%02d h', intdiv($m, 60), $m % 60) : $m.' min';
    }
}
