<?php

namespace App\Livewire;

use App\Livewire\Concerns\StartsScans;
use App\Models\Album;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\InboxMatcher;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app'), Title('Inbox')]
class Inbox extends Component
{
    use StartsScans;

    #[Url]
    public string $q = '';

    /** Inbox-Alben in Listen-Reihenfolge (wie in der Übersicht). */
    public static function orderedQuery()
    {
        return Album::where('location', 'inbox')
            ->orderByRaw('COALESCE(albumartist, folder_artist, title) COLLATE NOCASE')->orderBy('title')->orderBy('id');
    }

    /** Nächstes Album nach $albumId (sonst das vorherige, sonst null). */
    public static function neighbourOf(int $albumId): ?int
    {
        $ids = self::orderedQuery()->pluck('id')->all();
        $i = array_search($albumId, $ids, true);
        if ($i === false) {
            return $ids[0] ?? null;
        }

        return $ids[$i + 1] ?? $ids[$i - 1] ?? null;
    }

    public function render(Settings $settings)
    {
        $albums = self::orderedQuery()
            ->when($this->q !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$this->q.'%')
                ->orWhere('albumartist', 'like', '%'.$this->q.'%')->orWhere('dir', 'like', '%'.$this->q.'%')))
            ->get();

        // Künstler, die laut Verzeichnis anders heißen: als Hinweis an der Kachel
        $directory = app(ArtistDirectory::class);
        $renames = [];
        foreach (Track::whereIn('album_id', $albums->pluck('id'))->get(['album_id', 'artist', 'albumartist'])->groupBy('album_id') as $albumId => $tracks) {
            $r = $directory->renamesFor([...$tracks->pluck('artist')->all(), ...$tracks->pluck('albumartist')->all()]);
            if ($r) {
                $renames[$albumId] = $r;
            }
        }

        return view('livewire.inbox', [
            'albums' => $albums,
            'renames' => $renames,
            'existing' => app(InboxMatcher::class)->forAlbums($albums),
            'scan' => $this->scanStatus(),
            'inbox' => $settings->inbox(),
        ]);
    }
}
